<?php
/**
 * ProductLocatorInterface.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Loads one product, with the two decisions that `ProductRepositoryInterface`
 * leaves to every caller made once, here.
 *
 * @see ProductBatchLoaderInterface for loading many at once. Calling this
 *      interface in a loop is N repository loads; that one is a query.
 */
interface ProductLocatorInterface
{
    /**
     * @param int|null $storeId Null means the current store, resolved now.
     *
     * @return ProductInterface|null Null when no such SKU exists.
     */
    public function findBySku(string $sku, ?int $storeId = null): ?ProductInterface;

    /**
     * @throws NoSuchEntityException When no such SKU exists.
     */
    public function getBySku(string $sku, ?int $storeId = null): ProductInterface;

    /**
     * @return ProductInterface|null Null when no such id exists.
     */
    public function findById(int $productId, ?int $storeId = null): ?ProductInterface;

    /**
     * @throws NoSuchEntityException When no such id exists.
     */
    public function getById(int $productId, ?int $storeId = null): ProductInterface;

    /**
     * Drop memoised copies of these SKUs, in every store.
     */
    public function forget(string ...$skus): void;

    public function clear(): void;
}
