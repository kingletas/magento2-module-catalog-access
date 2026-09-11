<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Loads many products in one go.
 */
interface ProductBatchLoaderInterface
{
    /**
     * @param string[]      $skus       Duplicates and blanks are ignored.
     * @param int|null      $storeId    Null means the current store.
     * @param string[]      $attributes Attribute codes, or `['*']` for all.
     *                                  Defaults to the configured set.
     *
     * @return array<string, ProductInterface> Keyed by the SKU **as you asked
     *         for it**, not as it is spelled in the database. Misses are
     *         omitted, so the result is also the answer to "which of these
     *         exist".
     */
    public function loadBySkus(array $skus, ?int $storeId = null, array $attributes = []): array;

    /**
     * @param int[]    $productIds
     * @param string[] $attributes
     *
     * @return array<int, ProductInterface> Keyed by entity id, misses omitted.
     */
    public function loadByIds(array $productIds, ?int $storeId = null, array $attributes = []): array;

    /**
     * The same load, handed over a chunk at a time and never accumulated.
     *
     * @param string[]                                    $skus
     * @param callable(array<string, ProductInterface>): void $callback
     *                 Receives each chunk keyed the way `loadBySkus()` keys its
     *                 result. Never called with an empty chunk. Exceptions
     *                 propagate — a half-written export should stop, not
     *                 continue quietly.
     * @param string[]                                    $attributes
     * @return int How many products were handed over.
     */
    public function eachBySkus(
        array $skus,
        callable $callback,
        ?int $storeId = null,
        array $attributes = []
    ): int;

    /**
     * Load the configurable parent of each of these child SKUs.
     *
     * @param string[] $childSkus
     * @param string[] $attributes
     * @return array<string, ProductInterface> Child SKU => parent product.
     *         Standalone products are omitted; two children of the same parent
     *         share one loaded instance.
     */
    public function loadParentsBySkus(array $childSkus, ?int $storeId = null, array $attributes = []): array;
}
