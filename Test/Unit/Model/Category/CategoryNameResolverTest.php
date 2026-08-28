<?php
/**
 * CategoryNameResolverTest.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Category;

use Commerce\CatalogAccess\Api\StoreScopeInterface;
use Commerce\CatalogAccess\Model\Category\CategoryNameResolver;
use Commerce\CatalogAccess\Model\Db\StagedEntityFilter;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryNameResolverTest extends TestCase
{
    private const NAME_ATTRIBUTE_ID = 45;

    private AdapterInterface&MockObject $connection;
    private EavConfig&MockObject $eavConfig;
    private MetadataPool&MockObject $metadataPool;
    private ResourceConnection&MockObject $resourceConnection;
    private RequestMemo $memo;

    /** @var array<int, Select&MockObject> Selects handed out, in order. */
    private array $selects = [];

    /** @var array<int, array<int, array<string, mixed>>> Rows each select returns. */
    private array $rows = [];

    /** @var array<int, array<int, array{0: string, 1: mixed}>> Conditions per select. */
    private array $conditions = [];

    /** @var array<int, array<string, array{condition: string, columns: mixed}>> Joins per select. */
    private array $joins = [];

    private string $linkField = 'entity_id';

    private bool $stagingColumns = false;

    protected function setUp(): void
    {
        $this->selects = [];
        $this->rows = [];
        $this->conditions = [];
        $this->joins = [];

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->newSelect());
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn ($ident): string => '`' . $ident . '`');
        $this->connection->method('tableColumnExists')
            ->willReturnCallback(fn (): bool => $this->stagingColumns);
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (Select $select): array {
                foreach ($this->selects as $index => $candidate) {
                    if ($candidate === $select) {
                        return $this->rows[$index] ?? [];
                    }
                }

                return [];
            });

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => $table);

        $this->metadataPool = $this->createMock(MetadataPool::class);
        $this->metadataPool->method('getMetadata')
            ->willReturnCallback(function (string $entity): EntityMetadataInterface {
                $this->assertSame(CategoryInterface::class, $entity);

                $metadata = $this->createMock(EntityMetadataInterface::class);
                $metadata->method('getLinkField')->willReturn($this->linkField);

                return $metadata;
            });

        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->eavConfig->method('getAttribute')->willReturn($this->nameAttribute(self::NAME_ATTRIBUTE_ID));

        $this->memo = new RequestMemo(100);
    }

    public function testReturnsTheNamesForABatch(): void
    {
        $this->rows[0] = [
            ['entity_id' => '11', 'name' => 'Tops'],
            ['entity_id' => '12', 'name' => 'Tops'],
        ];

        $this->assertSame([11 => 'Tops', 12 => 'Tops'], $this->resolver()->getNames([11, 12]));
    }

    /**
     * The reason this class exists.
     */
    public function testAWholeBatchIsOneQuery(): void
    {
        $this->rows[0] = array_map(
            static fn (int $i): array => ['entity_id' => (string) $i, 'name' => 'Category ' . $i],
            range(1, 50)
        );

        $this->connection->expects($this->once())->method('fetchAll');

        $this->resolver()->getNames(range(1, 50));
    }

    /**
     * A category name is a store-scoped EAV value with no row at store scope
     * unless somebody typed an override.
     */
    public function testTheStoreValueFallsBackToTheDefaultScopeValue(): void
    {
        $this->resolver()->getNames([11], 3);

        $joins = $this->joins[0];

        $this->assertArrayHasKey('default_value', $joins);
        $this->assertArrayHasKey('store_value', $joins);
        $this->assertStringContainsString('default_value.store_id = 0', $joins['default_value']['condition']);
        $this->assertStringContainsString('store_value.store_id = 3', $joins['store_value']['condition']);
        $this->assertSame(
            'COALESCE(store_value.value, default_value.value)',
            (string) $joins['store_value']['columns']['name']
        );
        $this->assertStringContainsString(
            'attribute_id = ' . self::NAME_ATTRIBUTE_ID,
            $joins['default_value']['condition']
        );
    }

    public function testTheSameCategoryIsAskedAboutOnce(): void
    {
        $this->rows[0] = [['entity_id' => '11', 'name' => 'Tops']];

        $this->connection->expects($this->once())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertSame('Tops', $resolver->getName(11));
        $this->assertSame('Tops', $resolver->getName(11));
        $this->assertSame([11 => 'Tops'], $resolver->getNames([11]));
    }

    public function testEachStoreIsRememberedSeparately(): void
    {
        $this->rows[0] = [['entity_id' => '11', 'name' => 'Tops']];
        $this->rows[1] = [['entity_id' => '11', 'name' => 'Blouses']];

        $resolver = $this->resolver();

        $this->assertSame('Tops', $resolver->getName(11, 1));
        $this->assertSame('Blouses', $resolver->getName(11, 2));
        $this->assertSame('Tops', $resolver->getName(11, 1), 'Store 1 must not be answered with store 2s name.');
    }

    /**
     * Products keep category ids after the category is deleted, and a feed sees
     * the same dead id on every product that carries it.
     */
    public function testAnUnknownCategoryIsAskedAboutOnceAndThenRemembered(): void
    {
        $this->connection->expects($this->once())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertNull($resolver->getName(999));
        $this->assertNull($resolver->getName(999));
    }

    public function testOnlyRealNamesComeBack(): void
    {
        $this->rows[0] = [
            ['entity_id' => '11', 'name' => null],
            ['entity_id' => '12', 'name' => ''],
            ['entity_id' => '13', 'name' => 'Tops'],
        ];

        $this->assertSame([13 => 'Tops'], $this->resolver()->getNames([11, 12, 13]));
    }

    public function testNamesComeBackInTheOrderTheyWereAskedFor(): void
    {
        $this->rows[0] = [
            ['entity_id' => '12', 'name' => 'Tops'],
            ['entity_id' => '11', 'name' => 'Tops'],
        ];

        $this->assertSame([11 => 'Tops', 12 => 'Tops'], $this->resolver()->getNames([11, 12]));
    }

    public function testNothingToResolveIsNotAQuery(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertSame([], $resolver->getNames([]));
        $this->assertSame([], $resolver->getNames([0, -3]));
        $this->assertSame([], $resolver->getPaths([]));
        $this->assertSame('', $resolver->getPath(0));
    }

    /**
     * The two root levels are structure, not names.
     */
    public function testAPathExcludesTheTreeRootAndTheStoreRoot(): void
    {
        $this->rows[0] = [
            ['entity_id' => '1', 'path' => '1', 'level' => '0'],
            ['entity_id' => '2', 'path' => '1/2', 'level' => '1'],
            ['entity_id' => '11', 'path' => '1/2/11', 'level' => '2'],
            ['entity_id' => '12', 'path' => '1/2/11/12', 'level' => '3'],
        ];
        $this->rows[1] = [
            ['entity_id' => '11', 'name' => 'Women'],
            ['entity_id' => '12', 'name' => 'Scrub Tops'],
        ];

        $this->assertSame('Women > Scrub Tops', $this->resolver()->getPath(12));
    }

    public function testPathSegmentsAreTheSamePathUnjoined(): void
    {
        $this->rows[0] = [
            ['entity_id' => '12', 'path' => '1/2/11/12', 'level' => '3'],
            ['entity_id' => '11', 'path' => '1/2/11', 'level' => '2'],
        ];
        $this->rows[1] = [
            ['entity_id' => '11', 'name' => 'Women'],
            ['entity_id' => '12', 'name' => 'Scrub Tops'],
        ];

        $this->assertSame(['Women', 'Scrub Tops'], $this->resolver()->getPathSegments(12));
    }

    public function testASeparatorOfYourOwnIsHonoured(): void
    {
        $this->rows[0] = [['entity_id' => '12', 'path' => '1/2/11/12', 'level' => '3']];
        $this->rows[1] = [
            ['entity_id' => '11', 'name' => 'Women'],
            ['entity_id' => '12', 'name' => 'Scrub Tops'],
        ];

        $this->assertSame('Women/Scrub Tops', $this->resolver()->getPath(12, null, '/'));
    }

    /**
     * Siblings share every ancestor but one, so the ancestors of a whole set
     * are resolved together rather than once per path.
     */
    public function testManyPathsShareOneNameQuery(): void
    {
        $this->rows[0] = [
            ['entity_id' => '12', 'path' => '1/2/11/12', 'level' => '3'],
            ['entity_id' => '13', 'path' => '1/2/11/13', 'level' => '3'],
        ];
        $this->rows[1] = [
            ['entity_id' => '11', 'name' => 'Women'],
            ['entity_id' => '12', 'name' => 'Tops'],
            ['entity_id' => '13', 'name' => 'Trousers'],
        ];

        $this->connection->expects($this->exactly(2))->method('fetchAll');

        $this->assertSame(
            [12 => 'Women > Tops', 13 => 'Women > Trousers'],
            $this->resolver()->getPaths([12, 13])
        );
    }

    public function testAnUnknownCategoryHasNoPath(): void
    {
        $this->rows[0] = [];

        $this->assertSame('', $this->resolver()->getPath(999));
    }

    public function testJoinsOnEntityIdWhereThereIsNoStaging(): void
    {
        $this->resolver()->getNames([11]);

        $this->assertStringContainsString('`entity_id`', $this->joins[0]['default_value']['condition']);
        $this->assertSame([], $this->conditionsFor(0, 'entity.created_in <= ?'));
    }

    /**
     * On Adobe Commerce a category exists once per scheduled update.
     */
    public function testAStagedCatalogueReadsTheVersionThatIsLiveNow(): void
    {
        $this->linkField = 'row_id';
        $this->stagingColumns = true;

        $this->resolver()->getNames([11]);

        $this->assertStringContainsString('`row_id`', $this->joins[0]['default_value']['condition']);

        $createdIn = $this->conditionsFor(0, 'entity.created_in <= ?');
        $updatedIn = $this->conditionsFor(0, 'entity.updated_in > ?');

        $this->assertCount(1, $createdIn);
        $this->assertCount(1, $updatedIn);
        $this->assertEqualsWithDelta(time(), $createdIn[0], 5.0);
        $this->assertEqualsWithDelta(time(), $updatedIn[0], 5.0);
    }

    /**
     * A merchant on Open Source has `row_id` nowhere, and asking for a column
     * that does not exist is a hard SQL error rather than an empty result.
     */
    public function testTheVersionWindowIsSkippedWhereTheColumnsDoNotExist(): void
    {
        $this->linkField = 'row_id';
        $this->stagingColumns = false;

        $this->resolver()->getNames([11]);

        $this->assertSame([], $this->conditionsFor(0, 'entity.created_in <= ?'));
    }

    public function testAnUnusableNameAttributeIsNotQueriedAround(): void
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($this->nameAttribute(0));

        $this->connection->expects($this->never())->method('fetchAll');

        $resolver = new CategoryNameResolver(
            $this->resourceConnection,
            $this->stagedEntityFilter(),
            $eavConfig,
            $this->storeScope(),
            $this->memo
        );

        $this->assertSame([], $resolver->getNames([11]));
    }

    private function resolver(): CategoryNameResolver
    {
        return new CategoryNameResolver(
            $this->resourceConnection,
            $this->stagedEntityFilter(),
            $this->eavConfig,
            $this->storeScope(),
            $this->memo
        );
    }

    private function stagedEntityFilter(): StagedEntityFilter
    {
        return new StagedEntityFilter($this->resourceConnection, $this->metadataPool);
    }

    private function storeScope(): StoreScopeInterface
    {
        $storeScope = $this->createMock(StoreScopeInterface::class);
        $storeScope->method('getCurrentStoreId')->willReturn(1);

        return $storeScope;
    }

    private function nameAttribute(int $attributeId): AbstractAttribute&MockObject
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn($attributeId);
        $attribute->method('getBackendTable')->willReturn('catalog_category_entity_varchar');

        return $attribute;
    }

    /**
     * @return array<int, mixed> The bound values of every matching condition.
     */
    private function conditionsFor(int $selectIndex, string $condition): array
    {
        $values = [];

        foreach ($this->conditions[$selectIndex] ?? [] as [$candidate, $value]) {
            if ($candidate === $condition) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return Select&MockObject
     */
    private function newSelect(): Select
    {
        $index = count($this->selects);
        $select = $this->createMock(Select::class);

        $this->conditions[$index] = [];
        $this->joins[$index] = [];

        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')
            ->willReturnCallback(function ($name, $condition, $columns) use ($select, $index): Select {
                $alias = is_array($name) ? (string) array_key_first($name) : (string) $name;

                $this->joins[$index][$alias] = ['condition' => (string) $condition, 'columns' => $columns];

                return $select;
            });
        $select->method('where')
            ->willReturnCallback(function (string $condition, $value = null) use ($select, $index): Select {
                $this->conditions[$index][] = [$condition, $value];

                return $select;
            });

        $this->selects[$index] = $select;

        return $select;
    }
}
