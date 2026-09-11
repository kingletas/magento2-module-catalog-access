<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Product;

use Kingletas\CatalogAccess\Api\ProductBatchLoaderInterface;
use Kingletas\CatalogAccess\Api\StoreScopeInterface;
use Kingletas\Foundation\Api\ConfigurableParentSkuResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * One collection load per chunk, whatever the caller passes in.
 *
 * @see ProductBatchLoaderInterface for what these products are and are not.
 */
class ProductBatchLoader implements ProductBatchLoaderInterface
{
    /**
     * Set on every collection this class loads.
     */
    private const STOCK_FILTER_FLAG = 'has_stock_status_filter';

    private int $chunkSize;

    /**
     * @param string[] $defaultAttributes Attribute codes to select when the
     *                                    caller does not name any.
     * @param int      $chunkSize         Ids per query. `IN` lists are not
     *                                    free: a 40,000-SKU export passed in
     *                                    one go builds a megabyte-long
     *                                    statement, and MySQL rejects it
     *                                    outright past `max_allowed_packet`.
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ConfigurableParentSkuResolverInterface $parentSkuResolver,
        private readonly StoreScopeInterface $storeScope,
        private readonly array $defaultAttributes = ['*'],
        int $chunkSize = 500
    ) {
        $this->chunkSize = max(1, $chunkSize);
    }

    /**
     * @inheritDoc
     */
    public function loadBySkus(array $skus, ?int $storeId = null, array $attributes = []): array
    {
        $found = [];

        $this->eachBySkus(
            $skus,
            static function (array $products) use (&$found): void {
                $found += $products;
            },
            $storeId,
            $attributes
        );

        return $found;
    }

    /**
     * @inheritDoc
     */
    public function eachBySkus(
        array $skus,
        callable $callback,
        ?int $storeId = null,
        array $attributes = []
    ): int {
        // Requested spelling first, canonicalised second: the caller gets its
        // own keys back.
        $requested = [];

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);

            if ($sku === '') {
                continue;
            }

            $requested[mb_strtolower($sku)] ??= $sku;
        }

        if ($requested === []) {
            return 0;
        }

        $handed = 0;

        foreach (array_chunk(array_values($requested), $this->chunkSize) as $chunk) {
            $collection = $this->newCollection($storeId, $attributes);
            $collection->addFieldToFilter(ProductInterface::SKU, ['in' => $chunk]);

            $products = [];

            /** @var ProductInterface $product */
            foreach ($collection->getItems() as $product) {
                $key = $requested[mb_strtolower((string) $product->getSku())] ?? null;

                if ($key !== null) {
                    $products[$key] = $product;
                }
            }

            if ($products === []) {
                continue;
            }

            $handed += count($products);

            $callback($products);

            // The chunk is dropped here rather than being collected, which is
            // the entire difference between this and loadBySkus().
            unset($products, $collection);
        }

        return $handed;
    }

    /**
     * @inheritDoc
     */
    public function loadByIds(array $productIds, ?int $storeId = null, array $attributes = []): array
    {
        $ids = [];

        foreach ($productIds as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk(array_values($ids), $this->chunkSize) as $chunk) {
            $collection = $this->newCollection($storeId, $attributes);
            $collection->addIdFilter($chunk);

            /** @var ProductInterface $product */
            foreach ($collection->getItems() as $product) {
                $found[(int) $product->getId()] = $product;
            }
        }

        return $found;
    }

    /**
     * @inheritDoc
     */
    public function loadParentsBySkus(array $childSkus, ?int $storeId = null, array $attributes = []): array
    {
        $parentSkus = $this->parentSkuResolver->resolveMany($childSkus);

        if ($parentSkus === []) {
            return [];
        }

        // Distinct parents only.
        $parents = $this->loadBySkus(array_values(array_unique($parentSkus)), $storeId, $attributes);

        $byChild = [];

        foreach ($parentSkus as $childSku => $parentSku) {
            if (isset($parents[$parentSku])) {
                $byChild[$childSku] = $parents[$parentSku];
            }
        }

        return $byChild;
    }

    /**
     * @param string[] $attributes
     */
    private function newCollection(?int $storeId, array $attributes): Collection
    {
        $collection = $this->collectionFactory->create();

        // Store id before attribute selection: it decides which scope's values
        // the EAV joins read.
        $collection->setStoreId($storeId ?? $this->storeScope->getCurrentStoreId());
        $collection->setFlag(self::STOCK_FILTER_FLAG, true);

        $attributes = $attributes === [] ? $this->defaultAttributes : $attributes;

        // `addAttributeToSelect(['*'])` is not the same call as
        // `addAttributeToSelect('*')` in Magento; only the string means "all".
        $collection->addAttributeToSelect($attributes === ['*'] ? '*' : $attributes);

        // Deliberately no `addStoreFilter()`.
        return $collection;
    }
}
