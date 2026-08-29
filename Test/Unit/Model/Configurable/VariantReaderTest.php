<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Configurable;

use Commerce\CatalogAccess\Model\Configurable\VariantReader;
use Commerce\CatalogAccess\Model\Db\StagedEntityFilter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The three queries behind a variant lookup.
 */
class VariantReaderTest extends TestCase
{
    private VariantReader $reader;

    /** @var array<int, array<int, array<string, mixed>>> Rows, per select in order. */
    private array $rows = [];

    /** @var array<int, Select&MockObject> */
    private array $selects = [];

    /** @var string[] Value tables the reader asked for, in order. */
    private array $tablesRead = [];

    protected function setUp(): void
    {
        $this->rows = [];
        $this->selects = [];
        $this->tablesRead = [];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn (): Select => $this->newSelect());
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $id): string => '`' . $id . '`');
        // Open Source: no content staging, so no created_in/updated_in columns
        // and StagedEntityFilter adds no version predicate.
        $connection->method('tableColumnExists')->willReturn(false);
        $connection->method('fetchAll')->willReturnCallback(
            function (Select $select): array {
                $index = array_search($select, $this->selects, true);

                return $index === false ? [] : ($this->rows[$index] ?? []);
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            function (string $table): string {
                $this->tablesRead[] = $table;

                return $table;
            }
        );

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');

        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $this->reader = new VariantReader(
            $resourceConnection,
            new StagedEntityFilter($resourceConnection, $metadataPool)
        );
    }

    /**
     * A child can hang off more than one configurable, and the SKU is keyed
     * lower-cased because MySQL's collation matches case-insensitively.
     */
    public function testChildLinksCollectEveryParentAndKeyBySkuInLowerCase(): void
    {
        $this->rows[0] = [
            ['child_sku' => 'SKU-Child', 'child_link' => '10', 'parent_link' => '1'],
            ['child_sku' => 'SKU-Child', 'child_link' => '10', 'parent_link' => '2'],
        ];

        $children = $this->reader->fetchChildLinks(['SKU-Child']);

        $this->assertArrayHasKey('sku-child', $children);
        $this->assertSame(10, $children['sku-child']['link']);
        $this->assertSame([1 => 1, 2 => 2], $children['sku-child']['parents']);
    }

    public function testNoParentLinksMeansNoQueryForSuperAttributes(): void
    {
        $this->assertSame([], $this->reader->fetchSuperAttributes([]));
        $this->assertSame([], $this->tablesRead);
    }

    public function testSuperAttributesComeBackKeyedByParentThenAttribute(): void
    {
        $this->rows[0] = [
            ['parent_link' => '1', 'attribute_id' => '93', 'attribute_code' => 'color', 'backend_type' => 'int'],
            ['parent_link' => '1', 'attribute_id' => '94', 'attribute_code' => 'size', 'backend_type' => 'int'],
        ];

        $this->assertSame(
            [1 => [93 => 'color', 94 => 'size']],
            $this->reader->fetchSuperAttributes([1])
        );
    }

    public function testNoChildLinksMeansNoQueryForValues(): void
    {
        $this->assertSame([], $this->reader->fetchOptionValues([], [1 => [93 => 'color']]));
        $this->assertSame([], $this->tablesRead);
    }

    /**
     * The backend type comes from `eav_attribute`, not from an assumption that
     * a configurable axis is always int-backed.
     */
    public function testValuesAreReadFromTheTableTheBackendTypeNames(): void
    {
        $this->rows[0] = [
            ['parent_link' => '1', 'attribute_id' => '93', 'attribute_code' => 'fit', 'backend_type' => 'varchar'],
        ];
        $superAttributes = $this->reader->fetchSuperAttributes([1]);

        $this->rows[1] = [
            ['link_id' => '10', 'attribute_id' => '93', 'value' => '77'],
        ];

        $this->assertSame([10 => [93 => 77]], $this->reader->fetchOptionValues([10], $superAttributes));
        $this->assertContains('catalog_product_entity_varchar', $this->tablesRead);
    }

    /**
     * A child with no value for an axis is absent rather than present with a
     * zero, which would name option 0 as its colour.
     */
    public function testAnEmptyValueIsSkippedRatherThanReadAsZero(): void
    {
        $this->rows[0] = [
            ['parent_link' => '1', 'attribute_id' => '93', 'attribute_code' => 'color', 'backend_type' => 'int'],
        ];
        $superAttributes = $this->reader->fetchSuperAttributes([1]);

        $this->rows[1] = [
            ['link_id' => '10', 'attribute_id' => '93', 'value' => null],
            ['link_id' => '11', 'attribute_id' => '93', 'value' => ''],
            ['link_id' => '12', 'attribute_id' => '93', 'value' => '5'],
        ];

        $this->assertSame([12 => [93 => 5]], $this->reader->fetchOptionValues([10, 11, 12], $superAttributes));
    }

    private function newSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $this->selects[] = $select;

        return $select;
    }
}
