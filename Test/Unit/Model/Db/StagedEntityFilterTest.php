<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Db;

use Commerce\CatalogAccess\Model\Db\StagedEntityFilter;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StagedEntityFilterTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private AdapterInterface&MockObject $connection;
    private MetadataPool&MockObject $metadataPool;
    private ResourceConnection&MockObject $resourceConnection;
    private Select&MockObject $select;

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $conditions = [];

    private bool $stagingColumns = false;

    protected function setUp(): void
    {
        $this->conditions = [];

        $this->select = $this->createMock(Select::class);
        $this->select->method('where')
            ->willReturnCallback(function (string $condition, $value = null): Select {
                $this->conditions[] = [$condition, $value];

                return $this->select;
            });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('tableColumnExists')
            ->willReturnCallback(fn (): bool => $this->stagingColumns);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->metadataPool = $this->createMock(MetadataPool::class);
    }

    public function testTheLinkFieldComesFromTheEntitysMetadata(): void
    {
        $this->assertSame('row_id', $this->filter('row_id')->getLinkField(ProductInterface::class));
    }

    public function testTheLinkFieldIsAskedForOnce(): void
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');

        $this->metadataPool->expects($this->once())->method('getMetadata')->willReturn($metadata);

        $filter = new StagedEntityFilter($this->resourceConnection, $this->metadataPool);

        $filter->getLinkField(ProductInterface::class);
        $filter->getLinkField(ProductInterface::class);
        $filter->getLinkField(ProductInterface::class);
    }

    /**
     * An entity with no metadata registered cannot be staged, so `entity_id` is
     * the answer.
     */
    public function testAnEntityWithNoMetadataFallsBackToEntityId(): void
    {
        $this->metadataPool->method('getMetadata')
            ->willThrowException(new RuntimeException('no metadata for this entity'));

        $filter = new StagedEntityFilter($this->resourceConnection, $this->metadataPool);

        $this->assertSame('entity_id', $filter->getLinkField('Some\Unregistered\Entity'));
    }

    public function testAnEmptyLinkFieldIsNotUsedAsAColumnName(): void
    {
        $this->assertSame('entity_id', $this->filter('')->getLinkField(ProductInterface::class));
    }

    /**
     * Without the window one entity is several rows, and a batch of 100 comes
     * back as 130.
     */
    public function testAStagedTableIsNarrowedToTheLiveVersion(): void
    {
        $this->stagingColumns = true;

        $this->filter('row_id')->applyCurrentVersion(
            $this->select,
            'entity',
            'catalog_category_entity',
            self::NOW
        );

        $this->assertSame(
            [
                ['entity.created_in <= ?', self::NOW],
                ['entity.updated_in > ?', self::NOW],
            ],
            $this->conditions
        );
    }

    public function testTheAliasIsTheOneTheQueryUses(): void
    {
        $this->stagingColumns = true;

        $this->filter('row_id')->applyCurrentVersion($this->select, 'child', 'catalog_product_entity', self::NOW);

        $this->assertSame('child.created_in <= ?', $this->conditions[0][0]);
    }

    /**
     * Open Source has no staging columns, and asking for one is a hard SQL
     * error.
     */
    public function testAnUnstagedTableIsLeftAlone(): void
    {
        $this->stagingColumns = false;

        $this->filter('entity_id')->applyCurrentVersion($this->select, 'entity', 'catalog_category_entity');

        $this->assertSame([], $this->conditions);
    }

    public function testEachTableIsInspectedOnce(): void
    {
        $this->stagingColumns = true;

        $this->connection->expects($this->exactly(2))
            ->method('tableColumnExists')
            ->willReturn(true);

        $filter = $this->filter('row_id');

        $filter->applyCurrentVersion($this->select, 'a', 'catalog_product_entity', self::NOW);
        $filter->applyCurrentVersion($this->select, 'b', 'catalog_product_entity', self::NOW);
        $filter->applyCurrentVersion($this->select, 'c', 'catalog_category_entity', self::NOW);
    }

    public function testTheDefaultTimestampIsNow(): void
    {
        $this->stagingColumns = true;

        $this->filter('row_id')->applyCurrentVersion($this->select, 'entity', 'catalog_category_entity');

        $this->assertEqualsWithDelta(time(), $this->conditions[0][1], 5.0);
    }

    private function filter(string $linkField): StagedEntityFilter
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);

        $this->metadataPool->method('getMetadata')->willReturn($metadata);

        return new StagedEntityFilter($this->resourceConnection, $this->metadataPool);
    }
}
