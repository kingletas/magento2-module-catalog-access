<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Api;

/**
 * The configurable ↔ simple relationship, in both directions, in batches.
 */
interface ConfigurableVariantsInterface
{
    /**
     * @param string[] $parentSkus
     *
     * @return array<string, string[]> Parent SKU => child SKUs, in a stable
     *         order. Parents with no children, and SKUs that are not
     *         configurable, are omitted.
     */
    public function getChildSkus(array $parentSkus): array;

    /**
     * The option id each child carries for each of its parent's variant axes.
     *
     * @param string[] $childSkus
     * @return array<string, array<string, int>> Child SKU => attribute code =>
     *         option id, in the parent's configured axis order. Standalone
     *         products are omitted.
     */
    public function getVariantOptionIds(array $childSkus): array;

    /**
     * The same thing, resolved to labels for one store.
     *
     * @param string[] $childSkus
     * @param int|null $storeId Null means the current store.
     *
     * @return array<string, array<string, string>> Child SKU => attribute code
     *         => label, e.g. `['SCRUB-CEIL-S' => ['color' => 'Ceil Blue',
     *         'size' => 'Small']]`.
     */
    public function getVariantLabels(array $childSkus, ?int $storeId = null): array;
}
