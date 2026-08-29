<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Model\Configurable;

use Commerce\CatalogAccess\Model\Db\StagedEntityFilter;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * The three queries behind a variant lookup, and nothing else.
 */
class VariantReader
{
    /**
     * Attribute values live in `catalog_product_entity_<backend type>`.
     */
    private const VALUE_TABLE_PREFIX = 'catalog_product_entity_';

    /**
     * Backend type per attribute id, filled while reading the super attributes
     * so that the value tables can be chosen from the same query.
     *
     * @var array<int, string>
     */
    private array $backendTypes = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StagedEntityFilter $stagedEntityFilter
    ) {
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, array{link: int, parents: int[]}> Lowercased SKU.
     */
    public function fetchChildLinks(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $linkTable = $this->resourceConnection->getTableName('catalog_product_super_link');
        $linkField = $this->stagedEntityFilter->getLinkField(ProductInterface::class);

        $select = $connection->select()
            ->from(
                ['child' => $productTable],
                ['child_sku' => 'sku', 'child_link' => $linkField]
            )
            ->join(
                ['link' => $linkTable],
                'link.product_id = child.entity_id',
                ['parent_link' => 'parent_id']
            )
            ->where('child.sku IN (?)', $skus);

        $this->stagedEntityFilter->applyCurrentVersion($select, 'child', $productTable);

        $children = [];

        foreach ($connection->fetchAll($select) as $row) {
            $lower = mb_strtolower((string) $row['child_sku']);
            $parentLink = (int) $row['parent_link'];

            $children[$lower]['link'] = (int) $row['child_link'];
            $children[$lower]['parents'][$parentLink] = $parentLink;
        }

        return $children;
    }

    /**
     * @param int[] $parentLinks
     *
     * @return array<int, array<int, string>> Parent link id => attribute id =>
     *         attribute code, in configured axis order.
     */
    public function fetchSuperAttributes(array $parentLinks): array
    {
        if ($parentLinks === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['super' => $this->resourceConnection->getTableName('catalog_product_super_attribute')],
                ['parent_link' => 'product_id', 'attribute_id']
            )
            ->join(
                ['attribute' => $this->resourceConnection->getTableName('eav_attribute')],
                'attribute.attribute_id = super.attribute_id',
                ['attribute_code', 'backend_type']
            )
            ->where('super.product_id IN (?)', $parentLinks)
            // The order the merchant arranged the axes in, so "colour, size"
            // does not become "size, colour" between two runs of a feed.
            ->order(['super.position ASC', 'super.attribute_id ASC']);

        $byParent = [];

        foreach ($connection->fetchAll($select) as $row) {
            $byParent[(int) $row['parent_link']][(int) $row['attribute_id']] = (string) $row['attribute_code'];

            $this->backendTypes[(int) $row['attribute_id']] = (string) $row['backend_type'];
        }

        return $byParent;
    }

    /**
     * @param int[]                          $childLinks
     * @param array<int, array<int, string>> $superAttributes
     *
     * @return array<int, array<int, int>> Child link id => attribute id =>
     *         option id.
     */
    public function fetchOptionValues(array $childLinks, array $superAttributes): array
    {
        if ($childLinks === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->stagedEntityFilter->getLinkField(ProductInterface::class);
        $values = [];

        foreach ($this->attributeIdsByBackendType($superAttributes) as $backendType => $ids) {
            $select = $connection->select()
                ->from(
                    ['value' => $this->resourceConnection->getTableName(self::VALUE_TABLE_PREFIX . $backendType)],
                    ['link_id' => $linkField, 'attribute_id', 'value']
                )
                // Store scope is not consulted: Magento requires a
                // configurable's attributes to be global.
                ->where('value.store_id = ?', 0)
                ->where(sprintf('value.%s IN (?)', $connection->quoteIdentifier($linkField)), $childLinks)
                ->where('value.attribute_id IN (?)', $ids);

            foreach ($connection->fetchAll($select) as $row) {
                if ($row['value'] === null || $row['value'] === '') {
                    continue;
                }

                $values[(int) $row['link_id']][(int) $row['attribute_id']] = (int) $row['value'];
            }
        }

        return $values;
    }

    /**
     * Grouped so each value table is read once, whatever backs the select.
     *
     * @param array<int, array<int, string>> $superAttributes
     *
     * @return array<string, int[]>
     */
    private function attributeIdsByBackendType(array $superAttributes): array
    {
        $attributeIds = [];

        foreach ($superAttributes as $attributes) {
            foreach (array_keys($attributes) as $attributeId) {
                $attributeIds[$attributeId] = $attributeId;
            }
        }

        $byType = [];

        foreach ($attributeIds as $attributeId) {
            $byType[$this->backendTypes[$attributeId] ?? 'int'][] = $attributeId;
        }

        return $byType;
    }
}
