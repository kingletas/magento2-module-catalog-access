<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Api;

use Magento\Framework\DataObject;

/**
 * Reads an attribute value off a loaded entity and returns the type it says it
 * returns.
 */
interface AttributeValueReaderInterface
{
    /**
     * Is this attribute present on this object at all?
     */
    public function has(DataObject $entity, string $attributeCode): bool;

    /**
     * @return string Never null. An array or an object with no string form
     *                yields the default rather than "Array" or a fatal.
     */
    public function getString(DataObject $entity, string $attributeCode, string $default = ''): string;

    public function getStringOrNull(DataObject $entity, string $attributeCode): ?string;

    public function getInt(DataObject $entity, string $attributeCode, int $default = 0): int;

    public function getFloat(DataObject $entity, string $attributeCode, float $default = 0.0): float;

    /**
     * Yes/no semantics, not PHP truthiness.
     */
    public function getBool(DataObject $entity, string $attributeCode, bool $default = false): bool;

    /**
     * A multiselect, whatever form it arrives in.
     *
     * @return string[] Trimmed, blanks dropped, order preserved.
     */
    public function getList(DataObject $entity, string $attributeCode, string $separator = ','): array;

    /**
     * The option labels behind the stored ids, in the given store.
     *
     * @return string[]
     *
     * @see AttributeOptionLabelResolverInterface
     */
    public function getLabels(DataObject $entity, string $attributeCode, ?int $storeId = null): array;
}
