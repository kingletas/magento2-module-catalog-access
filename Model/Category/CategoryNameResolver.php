<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Category;

use Kingletas\CatalogAccess\Api\CategoryNameResolverInterface;
use Kingletas\CatalogAccess\Api\StoreScopeInterface;
use Kingletas\CatalogAccess\Model\Db\StagedEntityFilter;
use Kingletas\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\LocalizedException;

/**
 * Names for a whole set of category ids in one query, straight from the EAV
 * tables.
 *
 * @see CategoryNameResolverInterface for the two correctness traps.
 */
class CategoryNameResolver implements CategoryNameResolverInterface
{
    /**
     * Category levels that are structure rather than name: 0 is the tree root
     * ("Root Catalog"), 1 is a store's root category.
     */
    private const FIRST_NAMED_LEVEL = 2;

    private const ATTRIBUTE_NAME = 'name';

    /** @var array<int, array{path: string, level: int}>|null */
    private ?array $structureMemo = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StagedEntityFilter $stagedEntityFilter,
        private readonly EavConfig $eavConfig,
        private readonly StoreScopeInterface $storeScope,
        private readonly RequestMemo $memo
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(int $categoryId, ?int $storeId = null): ?string
    {
        return $this->getNames([$categoryId], $storeId)[$categoryId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getNames(array $categoryIds, ?int $storeId = null): array
    {
        $categoryIds = $this->normaliseIds($categoryIds);

        if ($categoryIds === []) {
            return [];
        }

        $storeId = $storeId ?? $this->storeScope->getCurrentStoreId();

        [$names, $pending] = $this->splitByMemo($categoryIds, $storeId);

        if ($pending !== []) {
            $names += $this->fetchAndMemoise($pending, $storeId);
        }

        return $this->inRequestedOrder($categoryIds, $names);
    }

    /**
     * Split what the memo already answers from what still has to be read.
     *
     * @param int[] $categoryIds
     *
     * @return array{0: array<int, string>, 1: int[]}
     */
    private function splitByMemo(array $categoryIds, int $storeId): array
    {
        $names = [];
        $pending = [];

        foreach ($categoryIds as $categoryId) {
            $key = $this->key($categoryId, $storeId);

            if (!$this->memo->has($key)) {
                $pending[] = $categoryId;
                continue;
            }

            $name = $this->memo->get($key);

            if ($name !== null) {
                $names[$categoryId] = $name;
            }
        }

        return [$names, $pending];
    }

    /**
     * @param int[] $pending
     *
     * @return array<int, string>
     */
    private function fetchAndMemoise(array $pending, int $storeId): array
    {
        $fetched = $this->fetchNames($pending, $storeId);
        $names = [];

        foreach ($pending as $categoryId) {
            $name = $fetched[$categoryId] ?? null;

            // Negative results are memoised too.
            $this->memo->set($this->key($categoryId, $storeId), $name);

            if ($name !== null) {
                $names[$categoryId] = $name;
            }
        }

        return $names;
    }

    /**
     * Back into the order the caller asked in; the memo and the query each
     * answer in their own.
     *
     * @param int[]              $categoryIds
     * @param array<int, string> $names
     *
     * @return array<int, string>
     */
    private function inRequestedOrder(array $categoryIds, array $names): array
    {
        $ordered = [];

        foreach ($categoryIds as $categoryId) {
            if (isset($names[$categoryId])) {
                $ordered[$categoryId] = $names[$categoryId];
            }
        }

        return $ordered;
    }

    /**
     * @inheritDoc
     */
    public function getPath(
        int $categoryId,
        ?int $storeId = null,
        string $separator = self::DEFAULT_SEPARATOR
    ): string {
        return $this->getPaths([$categoryId], $storeId, $separator)[$categoryId] ?? '';
    }

    /**
     * @inheritDoc
     */
    public function getPathSegments(int $categoryId, ?int $storeId = null): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $ancestors = $this->ancestorsOf([$categoryId]);

        return array_values($this->getNames($ancestors[$categoryId] ?? [], $storeId));
    }

    /**
     * @inheritDoc
     */
    public function getPaths(
        array $categoryIds,
        ?int $storeId = null,
        string $separator = self::DEFAULT_SEPARATOR
    ): array {
        $categoryIds = $this->normaliseIds($categoryIds);

        if ($categoryIds === []) {
            return [];
        }

        $ancestors = $this->ancestorsOf($categoryIds);

        if ($ancestors === []) {
            return [];
        }

        // Every ancestor of every requested category, resolved in one batch.
        $names = $this->getNames(array_merge(...array_values($ancestors)), $storeId);

        $paths = [];

        foreach ($categoryIds as $categoryId) {
            if (!isset($ancestors[$categoryId])) {
                continue;
            }

            $segments = [];

            foreach ($ancestors[$categoryId] as $ancestorId) {
                if (isset($names[$ancestorId])) {
                    $segments[] = $names[$ancestorId];
                }
            }

            if ($segments !== []) {
                $paths[$categoryId] = implode($separator, $segments);
            }
        }

        return $paths;
    }

    /**
     * Names for a batch, store value falling back to default scope.
     *
     * @param int[] $categoryIds
     *
     * @return array<int, string>
     */
    private function fetchNames(array $categoryIds, int $storeId): array
    {
        $attribute = $this->nameAttribute();

        if ($attribute === null) {
            return [];
        }

        [$attributeId, $valueTable] = $attribute;

        $connection = $this->resourceConnection->getConnection();
        $entityTable = $this->resourceConnection->getTableName('catalog_category_entity');
        $linkField = $this->stagedEntityFilter->getLinkField(CategoryInterface::class);

        $select = $connection->select()
            ->from(['entity' => $entityTable], ['entity_id'])
            ->joinLeft(
                ['default_value' => $valueTable],
                sprintf(
                    'default_value.%1$s = entity.%1$s AND default_value.attribute_id = %2$d'
                    . ' AND default_value.store_id = 0',
                    $connection->quoteIdentifier($linkField),
                    $attributeId
                ),
                []
            )
            ->joinLeft(
                ['store_value' => $valueTable],
                sprintf(
                    'store_value.%1$s = entity.%1$s AND store_value.attribute_id = %2$d'
                    . ' AND store_value.store_id = %3$d',
                    $connection->quoteIdentifier($linkField),
                    $attributeId,
                    $storeId
                ),
                // The store row where there is one, the default row otherwise.
                ['name' => new Expression('COALESCE(store_value.value, default_value.value)')]
            )
            ->where('entity.entity_id IN (?)', $categoryIds);

        $this->stagedEntityFilter->applyCurrentVersion($select, 'entity', $entityTable);

        $names = [];

        foreach ($connection->fetchAll($select) as $row) {
            $name = $row['name'] ?? null;

            if ($name !== null && (string) $name !== '') {
                $names[(int) $row['entity_id']] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The named ancestors of each category, top down, including itself.
     *
     * @param int[] $categoryIds
     *
     * @return array<int, int[]> Category id => ordered ancestor ids. Ids that
     *         do not exist are absent.
     */
    private function ancestorsOf(array $categoryIds): array
    {
        $structure = $this->structure();
        $ancestors = [];

        foreach ($categoryIds as $categoryId) {
            if (!isset($structure[$categoryId])) {
                continue;
            }

            $ids = array_map('intval', array_filter(explode('/', $structure[$categoryId]['path'])));

            // Structural levels are dropped by slicing the path, not by
            // comparing names.
            $ancestors[$categoryId] = array_slice($ids, self::FIRST_NAMED_LEVEL);
        }

        return $ancestors;
    }

    /**
     * The whole category tree's paths, in one query.
     *
     * @return array<int, array{path: string, level: int}>
     */
    private function structure(): array
    {
        if ($this->structureMemo !== null) {
            return $this->structureMemo;
        }

        $connection = $this->resourceConnection->getConnection();
        $entityTable = $this->resourceConnection->getTableName('catalog_category_entity');

        $select = $connection->select()->from(['entity' => $entityTable], ['entity_id', 'path', 'level']);

        $this->stagedEntityFilter->applyCurrentVersion($select, 'entity', $entityTable);

        $structure = [];

        foreach ($connection->fetchAll($select) as $row) {
            $structure[(int) $row['entity_id']] = [
                'path' => (string) $row['path'],
                'level' => (int) $row['level'],
            ];
        }

        return $this->structureMemo = $structure;
    }

    /**
     * @return array{0: int, 1: string}|null Attribute id and its value table.
     */
    private function nameAttribute(): ?array
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Category::ENTITY, self::ATTRIBUTE_NAME);
        } catch (LocalizedException) {
            return null;
        }

        $attributeId = (int) $attribute->getAttributeId();

        if ($attributeId === 0) {
            return null;
        }

        // From the attribute rather than hardcoded: a store that has moved
        // `name` to a static column, or renamed the table prefix, still works.
        return [$attributeId, (string) $attribute->getBackendTable()];
    }

    /**
     * @param int[] $categoryIds
     *
     * @return int[]
     */
    private function normaliseIds(array $categoryIds): array
    {
        $ids = [];

        foreach ($categoryIds as $categoryId) {
            $categoryId = (int) $categoryId;

            if ($categoryId > 0) {
                $ids[$categoryId] = $categoryId;
            }
        }

        return array_values($ids);
    }

    private function key(int $categoryId, int $storeId): string
    {
        return $categoryId . "\0" . $storeId;
    }
}
