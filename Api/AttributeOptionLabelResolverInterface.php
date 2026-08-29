<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Api;

/**
 * Option ids to labels — "Ceil Blue" from `247`.
 */
interface AttributeOptionLabelResolverInterface
{
    /**
     * @return string|null Null when the option, or the attribute, is unknown.
     */
    public function getLabel(string $attributeCode, int|string|null $optionId, ?int $storeId = null): ?string;

    /**
     * @param array<int|string> $optionIds
     * @param int|null          $storeId Null means the current store.
     *
     * @return array<int, string> Option id => label, misses omitted.
     */
    public function getLabels(string $attributeCode, array $optionIds, ?int $storeId = null): array;

    /**
     * Resolve a raw attribute value - an id, a list of ids, an array of ids, or
     * null - to labels.
     *
     * @return string[] Labels in the order the ids appeared. Empty when there
     *         is nothing to resolve — never a string, never null.
     */
    public function resolveValue(string $attributeCode, mixed $rawValue, ?int $storeId = null): array;
}
