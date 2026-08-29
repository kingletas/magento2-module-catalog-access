<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Model\Attribute;

use Commerce\CatalogAccess\Api\AttributeOptionLabelResolverInterface;
use Commerce\CatalogAccess\Api\StoreScopeInterface;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Exception\LocalizedException;

/**
 * Batch, memoise, and give the answer back in the order it was asked for.
 *
 * @see AttributeOptionLabelResolverInterface
 * @see OptionLabelReader for the two ways a label is read.
 */
class AttributeOptionLabelResolver implements AttributeOptionLabelResolverInterface
{
    private string $entityTypeCode;

    /**
     * @param string $entityTypeCode Which entity's attributes to read.
     *                               A di.xml argument rather than a constant,
     *                               so a virtualType covers categories or
     *                               customers without a line of new code.
     */
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly OptionLabelReader $reader,
        private readonly StoreScopeInterface $storeScope,
        private readonly RequestMemo $memo,
        string $entityTypeCode = Product::ENTITY
    ) {
        $this->entityTypeCode = $entityTypeCode;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(string $attributeCode, int|string|null $optionId, ?int $storeId = null): ?string
    {
        if ($optionId === null || $optionId === '') {
            return null;
        }

        return $this->getLabels($attributeCode, [$optionId], $storeId)[(int) $optionId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getLabels(string $attributeCode, array $optionIds, ?int $storeId = null): array
    {
        $optionIds = $this->normaliseIds($optionIds);

        if ($attributeCode === '' || $optionIds === []) {
            return [];
        }

        $storeId = $storeId ?? $this->storeScope->getCurrentStoreId();

        [$labels, $pending] = $this->splitByMemo($attributeCode, $optionIds, $storeId);

        if ($pending !== []) {
            $labels += $this->fetchAndMemoise($attributeCode, $pending, $storeId);
        }

        return $this->inRequestedOrder($optionIds, $labels);
    }

    /**
     * Split what the memo already answers from what still has to be read.
     *
     * @param int[] $optionIds
     *
     * @return array{0: array<int, string>, 1: int[]}
     */
    private function splitByMemo(string $attributeCode, array $optionIds, int $storeId): array
    {
        $labels = [];
        $pending = [];

        foreach ($optionIds as $optionId) {
            $key = $this->key($attributeCode, $optionId, $storeId);

            if (!$this->memo->has($key)) {
                $pending[] = $optionId;
                continue;
            }

            $label = $this->memo->get($key);

            if ($label !== null) {
                $labels[$optionId] = $label;
            }
        }

        return [$labels, $pending];
    }

    /**
     * Negative results are memoised too, so a deleted option id is not re-read
     * for every product.
     *
     * @param int[] $pending
     *
     * @return array<int, string>
     */
    private function fetchAndMemoise(string $attributeCode, array $pending, int $storeId): array
    {
        $fetched = $this->fetch($attributeCode, $pending, $storeId);
        $labels = [];

        foreach ($pending as $optionId) {
            $label = $fetched[$optionId] ?? null;

            $this->memo->set($this->key($attributeCode, $optionId, $storeId), $label);

            if ($label !== null) {
                $labels[$optionId] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param int[]              $optionIds
     * @param array<int, string> $labels
     *
     * @return array<int, string>
     */
    private function inRequestedOrder(array $optionIds, array $labels): array
    {
        $ordered = [];

        foreach ($optionIds as $optionId) {
            if (isset($labels[$optionId])) {
                $ordered[$optionId] = $labels[$optionId];
            }
        }

        return $ordered;
    }

    /**
     * @inheritDoc
     */
    public function resolveValue(string $attributeCode, mixed $rawValue, ?int $storeId = null): array
    {
        if ($rawValue === null || $rawValue === '' || is_bool($rawValue)) {
            return [];
        }

        // A multiselect stores "12,47,99" in one column, and a select stores
        // "12".
        $ids = is_array($rawValue)
            ? $rawValue
            : explode(',', (string) $rawValue);

        return array_values($this->getLabels($attributeCode, $ids, $storeId));
    }

    /**
     * @param int[] $optionIds
     *
     * @return array<int, string>
     */
    private function fetch(string $attributeCode, array $optionIds, int $storeId): array
    {
        $attribute = $this->attribute($attributeCode);

        // An attribute code nothing knows about resolves to nothing, which is
        // an answer rather than an error.
        return $attribute === null ? [] : $this->reader->labelsFor($attribute, $optionIds, $storeId);
    }

    private function attribute(string $attributeCode): ?AbstractAttribute
    {
        try {
            $attribute = $this->eavConfig->getAttribute($this->entityTypeCode, $attributeCode);
        } catch (LocalizedException) {
            return null;
        }

        // An unknown code does not throw here — it comes back as an empty
        // attribute with no id, which then answers every question with null.
        return (int) $attribute->getId() === 0 ? null : $attribute;
    }

    /**
     * @param array<int|string> $optionIds
     *
     * @return int[]
     */
    private function normaliseIds(array $optionIds): array
    {
        $ids = [];

        foreach ($optionIds as $optionId) {
            if (is_array($optionId) || is_object($optionId)) {
                continue;
            }

            $optionId = trim((string) $optionId);

            if ($optionId === '' || !ctype_digit($optionId)) {
                continue;
            }

            // Zero is a real option value: a yes/no attribute's "No" is option
            // 0.
            $id = (int) $optionId;

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    private function key(string $attributeCode, int $optionId, int $storeId): string
    {
        return $attributeCode . "\0" . $optionId . "\0" . $storeId;
    }
}
