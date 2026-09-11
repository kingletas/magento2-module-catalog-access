<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Api;

/**
 * Which categories these products are in — for the whole set, in one query.
 */
interface ProductCategoryIdsInterface
{
    /**
     * Categories assigned directly to these products, in admin sort order.
     *
     * @param int[] $productIds
     *
     * @return array<int, int[]> Product id => category ids. Products with no
     *         categories are omitted, so the result also answers "which of
     *         these are uncategorised".
     */
    public function getAssignedCategoryIds(array $productIds): array;

    /**
     * Categories the store's index places these products in, anchors included.
     *
     * @param int[]    $productIds
     * @param int|null $storeId Null means the current store.
     *
     * @return array<int, int[]> Product id => category ids.
     */
    public function getVisibleCategoryIds(array $productIds, ?int $storeId = null): array;
}
