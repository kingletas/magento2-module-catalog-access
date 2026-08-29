<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Model\Configurable;

use Commerce\CatalogAccess\Api\AttributeOptionLabelResolverInterface;
use Commerce\CatalogAccess\Api\ConfigurableVariantsInterface;
use Commerce\CatalogAccess\Model\Db\StagedEntityFilter;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Three queries, whatever the batch size, plus one label lookup per variant
 * axis — not per child.
 *
 * @see ConfigurableVariantsInterface for the thirty-second admin page this
 *      shape of query is the fix for.
 */
class ConfigurableVariants implements ConfigurableVariantsInterface
{
    private const MEMO_VARIANT = 'variant';

    private int $chunkSize;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StagedEntityFilter $stagedEntityFilter,
        private readonly VariantReader $reader,
        private readonly AttributeOptionLabelResolverInterface $optionLabels,
        private readonly RequestMemo $memo,
        int $chunkSize = 500
    ) {
        $this->chunkSize = max(1, $chunkSize);
    }

    /**
     * @inheritDoc
     */
    public function getChildSkus(array $parentSkus): array
    {
        $parentSkus = $this->normaliseSkus($parentSkus);

        if ($parentSkus === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $linkTable = $this->resourceConnection->getTableName('catalog_product_super_link');
        $linkField = $this->stagedEntityFilter->getLinkField(ProductInterface::class);

        $byParent = [];

        foreach (array_chunk(array_values($parentSkus), $this->chunkSize) as $chunk) {
            $select = $connection->select()
                ->from(['link' => $linkTable], [])
                ->join(
                    ['parent' => $productTable],
                    sprintf('parent.%s = link.parent_id', $connection->quoteIdentifier($linkField)),
                    ['parent_sku' => 'sku']
                )
                ->join(
                    // The child side references entity_id even where the parent
                    // side references row_id.
                    ['child' => $productTable],
                    'child.entity_id = link.product_id',
                    ['child_sku' => 'sku']
                )
                ->where('parent.sku IN (?)', array_values($chunk))
                // Stable order, so two runs of the same export produce the same
                // file and a diff of yesterday's is worth reading.
                ->order(['child.entity_id ASC']);

            $this->stagedEntityFilter->applyCurrentVersion($select, 'parent', $productTable);
            $this->stagedEntityFilter->applyCurrentVersion($select, 'child', $productTable);

            foreach ($connection->fetchAll($select) as $row) {
                $parentSku = $parentSkus[mb_strtolower((string) $row['parent_sku'])] ?? (string) $row['parent_sku'];
                $childSku = (string) $row['child_sku'];

                // Keyed while collecting: a staged catalogue returns a row per
                // surviving version, and a child must not appear twice.
                $byParent[$parentSku][$childSku] = $childSku;
            }
        }

        return array_map('array_values', $byParent);
    }

    /**
     * @inheritDoc
     */
    public function getVariantOptionIds(array $childSkus): array
    {
        $requested = $this->normaliseSkus($childSkus);

        if ($requested === []) {
            return [];
        }

        $resolved = [];
        $pending = [];

        foreach ($requested as $lower => $sku) {
            $key = self::MEMO_VARIANT . "\0" . $lower;

            if ($this->memo->has($key)) {
                $axes = $this->memo->get($key);

                if ($axes !== []) {
                    $resolved[$sku] = $axes;
                }

                continue;
            }

            $pending[$lower] = $sku;
        }

        foreach (array_chunk($pending, $this->chunkSize, true) as $chunk) {
            foreach ($this->fetchVariants($chunk) as $lower => $axes) {
                // Only SKUs that were asked for.
                $requestedSku = $chunk[$lower] ?? null;

                if ($requestedSku === null) {
                    continue;
                }

                $this->memo->set(self::MEMO_VARIANT . "\0" . $lower, $axes);

                if ($axes !== []) {
                    $resolved[$requestedSku] = $axes;
                }
            }
        }

        return $this->inRequestedOrder($requested, $resolved);
    }

    /**
     * @inheritDoc
     */
    public function getVariantLabels(array $childSkus, ?int $storeId = null): array
    {
        $variants = $this->getVariantOptionIds($childSkus);

        if ($variants === []) {
            return [];
        }

        // Every option id for one attribute, resolved in one call — the whole
        // point.
        $idsByAttribute = [];

        foreach ($variants as $axes) {
            foreach ($axes as $attributeCode => $optionId) {
                $idsByAttribute[$attributeCode][$optionId] = $optionId;
            }
        }

        $labelsByAttribute = [];

        foreach ($idsByAttribute as $attributeCode => $optionIds) {
            $labelsByAttribute[$attributeCode] = $this->optionLabels->getLabels(
                $attributeCode,
                array_values($optionIds),
                $storeId
            );
        }

        $labelled = [];

        foreach ($variants as $childSku => $axes) {
            foreach ($axes as $attributeCode => $optionId) {
                $label = $labelsByAttribute[$attributeCode][$optionId] ?? null;

                // An option that has been deleted from the attribute leaves the
                // axis out rather than putting an id where a label belongs.
                if ($label !== null) {
                    $labelled[$childSku][$attributeCode] = $label;
                }
            }
        }

        return $labelled;
    }

    /**
     * @param array<string, string> $chunk Lowercased SKU => requested SKU.
     *
     * @return array<string, array<string, int>> Lowercased SKU => axes.
     */
    private function fetchVariants(array $chunk): array
    {
        $children = $this->reader->fetchChildLinks(array_values($chunk));

        // Every SKU asked about gets an answer, including the empty one: a
        // standalone product is a fact worth remembering for the request.
        $axes = array_fill_keys(array_keys($chunk), []);

        if ($children === []) {
            return $axes;
        }

        $superAttributes = $this->reader->fetchSuperAttributes($this->parentLinksOf($children));

        if ($superAttributes === []) {
            return $axes;
        }

        $values = $this->reader->fetchOptionValues(
            array_values(array_column($children, 'link')),
            $superAttributes
        );

        foreach ($children as $lower => $child) {
            if (array_key_exists($lower, $axes)) {
                $axes[$lower] = $this->axesOf($child, $superAttributes, $values);
            }
        }

        return $axes;
    }

    /**
     * Every parent any of these children hangs off, once.
     *
     * @param array<string, array{link: int, parents: int[]}> $children
     *
     * @return int[]
     */
    private function parentLinksOf(array $children): array
    {
        $parentLinks = [];

        foreach ($children as $child) {
            foreach ($child['parents'] as $parentLink) {
                $parentLinks[$parentLink] = $parentLink;
            }
        }

        return array_values($parentLinks);
    }

    /**
     * The first parent to define an axis wins it; a shared child has one value
     * for it either way.
     *
     * @param array{link: int, parents: int[]}  $child
     * @param array<int, array<int, string>>    $superAttributes
     * @param array<int, array<int, int>>       $values
     *
     * @return array<string, int>
     */
    private function axesOf(array $child, array $superAttributes, array $values): array
    {
        $childAxes = [];

        foreach ($child['parents'] as $parentLink) {
            foreach ($superAttributes[$parentLink] ?? [] as $attributeId => $attributeCode) {
                $optionId = $values[$child['link']][$attributeId] ?? null;

                if ($optionId !== null && !isset($childAxes[$attributeCode])) {
                    $childAxes[$attributeCode] = $optionId;
                }
            }
        }

        return $childAxes;
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, string> Lowercased SKU => SKU as requested.
     */
    private function normaliseSkus(array $skus): array
    {
        $normalised = [];

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);

            if ($sku !== '') {
                $normalised[mb_strtolower($sku)] ??= $sku;
            }
        }

        return $normalised;
    }

    /**
     * @param array<string, string>                $requested
     * @param array<string, array<string, mixed>>  $resolved
     *
     * @return array<string, array<string, mixed>>
     */
    private function inRequestedOrder(array $requested, array $resolved): array
    {
        $ordered = [];

        foreach ($requested as $sku) {
            if (isset($resolved[$sku])) {
                $ordered[$sku] = $resolved[$sku];
            }
        }

        return $ordered;
    }
}
