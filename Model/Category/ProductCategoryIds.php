<?php
/**
 * ProductCategoryIds.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Model\Category;

use Commerce\CatalogAccess\Api\ProductCategoryIdsInterface;
use Commerce\CatalogAccess\Api\StoreScopeInterface;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\App\ResourceConnection;

/**
 * One query per batch, against the table that actually answers the question.
 */
class ProductCategoryIds implements ProductCategoryIdsInterface
{
    private const ASSIGNED = 'assigned';
    private const VISIBLE = 'visible';

    private int $chunkSize;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TableMaintainer $tableMaintainer,
        private readonly StoreScopeInterface $storeScope,
        private readonly RequestMemo $memo,
        int $chunkSize = 1000
    ) {
        $this->chunkSize = max(1, $chunkSize);
    }

    /**
     * @inheritDoc
     */
    public function getAssignedCategoryIds(array $productIds): array
    {
        return $this->resolve(
            self::ASSIGNED,
            $productIds,
            0,
            fn (array $chunk): array => $this->fetch(
                $this->resourceConnection->getTableName('catalog_category_product'),
                $chunk
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function getVisibleCategoryIds(array $productIds, ?int $storeId = null): array
    {
        $storeId = $storeId ?? $this->storeScope->getCurrentStoreId();

        return $this->resolve(
            self::VISIBLE,
            $productIds,
            $storeId,
            fn (array $chunk): array => $this->fetch(
                // Dimensional index tables are resolved through the indexer's
                // maintainer, not by name.
                $this->tableMaintainer->getMainTable($storeId),
                $chunk
            )
        );
    }

    /**
     * Memo, batch, order — the shape both methods share.
     *
     * @param int[]                     $productIds
     * @param callable(int[]): array<int, int[]> $fetch
     *
     * @return array<int, int[]>
     */
    private function resolve(string $kind, array $productIds, int $storeId, callable $fetch): array
    {
        $productIds = $this->normaliseIds($productIds);

        if ($productIds === []) {
            return [];
        }

        [$resolved, $pending] = $this->splitByMemo($kind, $productIds, $storeId);

        if ($pending !== []) {
            $resolved += $this->fetchAndMemoise($kind, $pending, $storeId, $fetch);
        }

        return $this->inRequestedOrder($productIds, $resolved);
    }

    /**
     * Split what the memo already answers from what still has to be read.
     *
     * @param int[] $productIds
     *
     * @return array{0: array<int, int[]>, 1: int[]}
     */
    private function splitByMemo(string $kind, array $productIds, int $storeId): array
    {
        $resolved = [];
        $pending = [];

        foreach ($productIds as $productId) {
            $key = $this->key($kind, $productId, $storeId);

            if (!$this->memo->has($key)) {
                $pending[] = $productId;
                continue;
            }

            $categoryIds = $this->memo->get($key);

            if ($categoryIds !== []) {
                $resolved[$productId] = $categoryIds;
            }
        }

        return [$resolved, $pending];
    }

    /**
     * @param int[]                              $pending
     * @param callable(int[]): array<int, int[]> $fetch
     *
     * @return array<int, int[]>
     */
    private function fetchAndMemoise(string $kind, array $pending, int $storeId, callable $fetch): array
    {
        $resolved = [];

        foreach (array_chunk($pending, $this->chunkSize) as $chunk) {
            $fetched = $fetch($chunk);

            foreach ($chunk as $productId) {
                $categoryIds = $fetched[$productId] ?? [];

                // Including the empty ones.
                $this->memo->set($this->key($kind, $productId, $storeId), $categoryIds);

                if ($categoryIds !== []) {
                    $resolved[$productId] = $categoryIds;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param int[]             $productIds
     * @param array<int, int[]> $resolved
     *
     * @return array<int, int[]>
     */
    private function inRequestedOrder(array $productIds, array $resolved): array
    {
        $ordered = [];

        foreach ($productIds as $productId) {
            if (isset($resolved[$productId])) {
                $ordered[$productId] = $resolved[$productId];
            }
        }

        return $ordered;
    }

    private function key(string $kind, int $productId, int $storeId): string
    {
        return $kind . "\0" . $productId . "\0" . $storeId;
    }

    /**
     * @param int[] $productIds
     *
     * @return array<int, int[]>
     */
    private function fetch(string $table, array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($table, ['product_id', 'category_id'])
            ->where('product_id IN (?)', $productIds)
            // Admin sort order, then id, so that two runs over the same data
            // produce the same feed and a diff of yesterday's file is readable.
            ->order(['position ASC', 'category_id ASC']);

        $byProduct = [];

        foreach ($connection->fetchAll($select) as $row) {
            $productId = (int) $row['product_id'];
            $categoryId = (int) $row['category_id'];

            // Keyed while collecting, so a table that holds more than one row
            // for a pair cannot put the same category in a feed twice.
            $byProduct[$productId][$categoryId] = $categoryId;
        }

        return array_map('array_values', $byProduct);
    }

    /**
     * @param int[] $productIds
     *
     * @return int[]
     */
    private function normaliseIds(array $productIds): array
    {
        $ids = [];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            if ($productId > 0) {
                $ids[$productId] = $productId;
            }
        }

        return array_values($ids);
    }
}
