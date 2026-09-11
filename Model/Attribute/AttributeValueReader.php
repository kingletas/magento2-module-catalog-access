<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Attribute;

use Kingletas\CatalogAccess\Api\AttributeOptionLabelResolverInterface;
use Kingletas\CatalogAccess\Api\AttributeValueReaderInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Phrase;

/**
 * Coercion rules, written down once.
 *
 * @see AttributeValueReaderInterface for the defects this closes.
 */
class AttributeValueReader implements AttributeValueReaderInterface
{
    /**
     * Strings that mean "no" when a yes/no attribute has been through a form,
     * an import file or a JSON payload on its way here.
     */
    private const FALSE_WORDS = ['0', 'false', 'no', 'off', 'n'];

    private const TRUE_WORDS = ['1', 'true', 'yes', 'on', 'y'];

    public function __construct(
        private readonly AttributeOptionLabelResolverInterface $optionLabels
    ) {
    }

    /**
     * @inheritDoc
     */
    public function has(DataObject $entity, string $attributeCode): bool
    {
        return $attributeCode !== '' && $entity->hasData($attributeCode);
    }

    /**
     * @inheritDoc
     */
    public function getString(DataObject $entity, string $attributeCode, string $default = ''): string
    {
        return $this->getStringOrNull($entity, $attributeCode) ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function getStringOrNull(DataObject $entity, string $attributeCode): ?string
    {
        $value = $this->raw($entity, $attributeCode);

        if ($value === null || is_bool($value)) {
            return null;
        }

        if (is_scalar($value)) {
            $value = (string) $value;

            return $value === '' ? null : $value;
        }

        // A Phrase, or anything else that knows its own string form.
        if ($value instanceof Phrase || (is_object($value) && method_exists($value, '__toString'))) {
            $value = (string) $value;

            return $value === '' ? null : $value;
        }

        // An array is not a string.
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getInt(DataObject $entity, string $attributeCode, int $default = 0): int
    {
        $value = $this->raw($entity, $attributeCode);

        if (is_int($value)) {
            return $value;
        }

        // is_numeric first, so that "abc" is the default rather than 0 — the
        // two mean very different things to a price or a quantity.
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @inheritDoc
     */
    public function getFloat(DataObject $entity, string $attributeCode, float $default = 0.0): float
    {
        $value = $this->raw($entity, $attributeCode);

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @inheritDoc
     */
    public function getBool(DataObject $entity, string $attributeCode, bool $default = false): bool
    {
        $value = $this->raw($entity, $attributeCode);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (!is_string($value)) {
            return $default;
        }

        $normalised = strtolower(trim($value));

        // An empty value is an unset attribute, not a "no".
        if ($normalised === '') {
            return $default;
        }

        if (in_array($normalised, self::TRUE_WORDS, true)) {
            return true;
        }

        if (in_array($normalised, self::FALSE_WORDS, true)) {
            return false;
        }

        return $default;
    }

    /**
     * @inheritDoc
     */
    public function getList(DataObject $entity, string $attributeCode, string $separator = ','): array
    {
        $value = $this->raw($entity, $attributeCode);

        if ($value === null || is_bool($value)) {
            return [];
        }

        $parts = is_array($value) ? $value : explode($separator, (string) $this->scalarise($value));

        $list = [];

        foreach ($parts as $part) {
            if (is_array($part) || (is_object($part) && !method_exists($part, '__toString'))) {
                continue;
            }

            $part = trim((string) $part);

            if ($part !== '') {
                $list[] = $part;
            }
        }

        return $list;
    }

    /**
     * @inheritDoc
     */
    public function getLabels(DataObject $entity, string $attributeCode, ?int $storeId = null): array
    {
        return $this->optionLabels->resolveValue(
            $attributeCode,
            $this->raw($entity, $attributeCode),
            $storeId
        );
    }

    private function raw(DataObject $entity, string $attributeCode): mixed
    {
        return $attributeCode === '' ? null : $entity->getData($attributeCode);
    }

    private function scalarise(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return is_object($value) && method_exists($value, '__toString') ? (string) $value : '';
    }
}
