<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Attribute;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Where an option's label actually comes from — the two ways, and the choice
 * between them.
 */
class OptionLabelReader
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param int[] $optionIds
     *
     * @return array<int, string>
     */
    public function labelsFor(AbstractAttribute $attribute, array $optionIds, int $storeId): array
    {
        if (!$attribute->usesSource()) {
            // A text, date or price attribute has no options.
            return [];
        }

        return $this->hasTableSource($attribute)
            ? $this->fromTables($optionIds, $storeId)
            : $this->fromSource($attribute, $optionIds);
    }

    /**
     * One query, store value over default value.
     *
     * @param int[] $optionIds
     * @return array<int, string>
     */
    private function fromTables(array $optionIds, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $valueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        $select = $connection->select()
            ->from(['default_value' => $valueTable], ['option_id'])
            ->joinLeft(
                ['store_value' => $valueTable],
                sprintf('store_value.option_id = default_value.option_id AND store_value.store_id = %d', $storeId),
                ['label' => new Expression('COALESCE(store_value.value, default_value.value)')]
            )
            ->where('default_value.store_id = ?', 0)
            ->where('default_value.option_id IN (?)', $optionIds);

        $labels = [];

        foreach ($connection->fetchAll($select) as $row) {
            $label = $row['label'] ?? null;

            if ($label !== null && (string) $label !== '') {
                $labels[(int) $row['option_id']] = (string) $label;
            }
        }

        return $labels;
    }

    /**
     * Ask the source model once and index its answer.
     *
     * @param int[] $optionIds
     * @return array<int, string>
     */
    private function fromSource(AbstractAttribute $attribute, array $optionIds): array
    {
        try {
            $options = $attribute->getSource()->getAllOptions();
        } catch (\Exception) {
            // A custom source model reaching for a request, a registry or a
            // table that is not there yet.
            return [];
        }

        $byValue = $this->indexOptions($options);
        $labels = [];

        foreach ($optionIds as $optionId) {
            if (isset($byValue[$optionId]) && $byValue[$optionId] !== '') {
                $labels[$optionId] = $byValue[$optionId];
            }
        }

        return $labels;
    }

    /**
     * @param iterable<mixed> $options
     *
     * @return array<int, string>
     */
    private function indexOptions(iterable $options): array
    {
        $byValue = [];

        foreach ($options as $option) {
            if (!is_array($option) || !isset($option['value'])) {
                continue;
            }

            // Optgroups nest their options one level down.
            if (is_array($option['value'])) {
                foreach ($this->indexOptions($option['value']) as $value => $label) {
                    $byValue[$value] = $label;
                }

                continue;
            }

            $byValue[(int) $option['value']] = (string) ($option['label'] ?? '');
        }

        return $byValue;
    }

    private function hasTableSource(AbstractAttribute $attribute): bool
    {
        try {
            return $attribute->getSource() instanceof TableSource;
        } catch (\Exception) {
            return false;
        }
    }
}
