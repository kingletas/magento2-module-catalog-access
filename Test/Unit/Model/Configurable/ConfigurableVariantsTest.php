<?php
/**
 * ConfigurableVariantsTest.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Configurable;

use Commerce\CatalogAccess\Api\AttributeOptionLabelResolverInterface;
use Commerce\CatalogAccess\Model\Configurable\ConfigurableVariants;
use Commerce\CatalogAccess\Model\Configurable\VariantReader;
use Commerce\CatalogAccess\Model\Db\StagedEntityFilter;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigurableVariantsTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private AttributeOptionLabelResolverInterface&MockObject $optionLabels;
    private ResourceConnection&MockObject $resourceConnection;
    private RequestMemo $memo;

    /** @var array<int, Select&MockObject> */
    private array $selects = [];

    /** @var array<int, array<int, array<string, mixed>>> Rows per select. */
    private array $rows = [];

    /** @var array<int, string> Main table per select. */
    private array $tables = [];

    /** @var array<int, array<int, array{0: string, 1: mixed}>> */
    private array $conditions = [];

    /** @var array<int, array<int, array{0: string, 1: string}>> Joins per select. */
    private array $joins = [];

    private string $linkField = 'entity_id';

    private bool $stagingColumns = false;

    protected function setUp(): void
    {
        $this->selects = [];
        $this->rows = [];
        $this->tables = [];
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

        $this->optionLabels = $this->createMock(AttributeOptionLabelResolverInterface::class);
        $this->memo = new RequestMemo(100);
    }

    public function testListsTheChildrenOfEachParent(): void
    {
        $this->rows[0] = [
            ['parent_sku' => 'SHIRT', 'child_sku' => 'SHIRT-S'],
            ['parent_sku' => 'SHIRT', 'child_sku' => 'SHIRT-M'],
            ['parent_sku' => 'TROUSER', 'child_sku' => 'TROUSER-32'],
        ];

        $this->assertSame(
            ['SHIRT' => ['SHIRT-S', 'SHIRT-M'], 'TROUSER' => ['TROUSER-32']],
            $this->variants()->getChildSkus(['SHIRT', 'TROUSER'])
        );
    }

    public function testChildrenOfManyParentsAreOneQuery(): void
    {
        $this->connection->expects($this->once())->method('fetchAll');

        $this->variants()->getChildSkus(array_map(static fn (int $i): string => 'P-' . $i, range(1, 50)));
    }

    public function testAParentWithNoChildrenIsAbsent(): void
    {
        $this->rows[0] = [['parent_sku' => 'SHIRT', 'child_sku' => 'SHIRT-S']];

        $this->assertSame(
            ['SHIRT'],
            array_keys($this->variants()->getChildSkus(['SHIRT', 'SIMPLE']))
        );
    }

    /**
     * The two id columns of `catalog_product_super_link` point at different
     * things under staging.
     */
    public function testTheParentAndChildSidesJoinOnDifferentColumns(): void
    {
        $this->linkField = 'row_id';

        $this->variants()->getChildSkus(['SHIRT']);

        $joins = $this->joins[0];

        $this->assertSame('parent.`row_id` = link.parent_id', $joins['parent']);
        $this->assertSame('child.entity_id = link.product_id', $joins['child']);
    }

    public function testAStagedCatalogueReadsTheVersionThatIsLiveNow(): void
    {
        $this->linkField = 'row_id';
        $this->stagingColumns = true;

        $this->variants()->getChildSkus(['SHIRT']);

        $conditions = array_column($this->conditions[0], 0);

        $this->assertContains('parent.created_in <= ?', $conditions);
        $this->assertContains('parent.updated_in > ?', $conditions);
        $this->assertContains('child.created_in <= ?', $conditions);
    }

    /**
     * A staged catalogue returns a row per surviving version, and a child that
     * appears twice in an export is a duplicate line in somebody's feed.
     */
    public function testAChildCannotBeListedTwice(): void
    {
        $this->rows[0] = [
            ['parent_sku' => 'SHIRT', 'child_sku' => 'SHIRT-S'],
            ['parent_sku' => 'SHIRT', 'child_sku' => 'SHIRT-S'],
        ];

        $this->assertSame(['SHIRT' => ['SHIRT-S']], $this->variants()->getChildSkus(['SHIRT']));
    }

    public function testResolvesEachChildsOptionIdsPerAxis(): void
    {
        $this->givenACatalogue();

        $this->assertSame(
            [
                'SHIRT-CEIL-S' => ['color' => 247, 'size' => 10],
                'SHIRT-WINE-M' => ['color' => 248, 'size' => 11],
            ],
            $this->variants()->getVariantOptionIds(['SHIRT-CEIL-S', 'SHIRT-WINE-M'])
        );
    }

    /**
     * Three queries — children, axes, values — however many children are asked
     * about.
     */
    public function testAnyNumberOfChildrenCostsThreeQueries(): void
    {
        $this->givenACatalogue();

        $this->connection->expects($this->exactly(3))->method('fetchAll');

        $this->variants()->getVariantOptionIds(['SHIRT-CEIL-S', 'SHIRT-WINE-M']);
    }

    public function testAxesComeBackInTheOrderTheMerchantArrangedThem(): void
    {
        $this->givenACatalogue();

        $ids = $this->variants()->getVariantOptionIds(['SHIRT-CEIL-S']);

        $this->assertSame(['color', 'size'], array_keys($ids['SHIRT-CEIL-S']));
    }

    public function testAStandaloneProductIsAbsentRatherThanEmpty(): void
    {
        $this->givenACatalogue();

        $ids = $this->variants()->getVariantOptionIds(['SHIRT-CEIL-S', 'SIMPLE-1']);

        $this->assertArrayNotHasKey('SIMPLE-1', $ids);
    }

    public function testTheSameChildIsAskedAboutOnce(): void
    {
        $this->givenACatalogue();

        $this->connection->expects($this->exactly(3))->method('fetchAll');

        $variants = $this->variants();
        $variants->getVariantOptionIds(['SHIRT-CEIL-S']);
        $variants->getVariantOptionIds(['SHIRT-CEIL-S']);
    }

    public function testAValueTableIsChosenFromTheAttributesBackendType(): void
    {
        $this->givenACatalogue(backendType: 'varchar');

        $this->variants()->getVariantOptionIds(['SHIRT-CEIL-S']);

        $this->assertSame('catalog_product_entity_varchar', $this->tables[2]);
    }

    /**
     * Configurable attributes are global, so the default row is read
     * explicitly.
     */
    public function testOptionIdsAreReadAtDefaultScope(): void
    {
        $this->givenACatalogue();

        $this->variants()->getVariantOptionIds(['SHIRT-CEIL-S']);

        $this->assertContains(['value.store_id = ?', 0], $this->conditions[2]);
    }

    /**
     * The fix that made the thirty-second page load fast: the option map is
     * built once per attribute, not once per child.
     */
    public function testLabelsAreResolvedOncePerAttributeNotOncePerChild(): void
    {
        $this->givenACatalogue();

        $calls = [];

        $this->optionLabels->method('getLabels')
            ->willReturnCallback(function (string $code, array $ids, ?int $storeId) use (&$calls): array {
                $calls[] = [$code, $ids, $storeId];

                return $code === 'color'
                    ? [247 => 'Ceil Blue', 248 => 'Wine']
                    : [10 => 'Small', 11 => 'Medium'];
            });

        $labels = $this->variants()->getVariantLabels(['SHIRT-CEIL-S', 'SHIRT-WINE-M'], 3);

        $this->assertSame(
            [
                'SHIRT-CEIL-S' => ['color' => 'Ceil Blue', 'size' => 'Small'],
                'SHIRT-WINE-M' => ['color' => 'Wine', 'size' => 'Medium'],
            ],
            $labels
        );
        $this->assertCount(2, $calls, 'Two axes, two lookups — not one per child per axis.');
        $this->assertSame(['color', [247, 248], 3], $calls[0]);
    }

    /**
     * An option id rendered as a colour is wrong everywhere; a missing colour
     * is visibly missing.
     */
    public function testAnOptionWithNoLabelLeavesTheAxisOutRatherThanEmittingAnId(): void
    {
        $this->givenACatalogue();

        $this->optionLabels->method('getLabels')
            ->willReturnCallback(
                static fn (string $code): array => $code === 'color' ? [247 => 'Ceil Blue'] : []
            );

        $this->assertSame(
            ['SHIRT-CEIL-S' => ['color' => 'Ceil Blue']],
            $this->variants()->getVariantLabels(['SHIRT-CEIL-S'])
        );
    }

    public function testNothingToResolveIsNotAQuery(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $variants = $this->variants();

        $this->assertSame([], $variants->getChildSkus([]));
        $this->assertSame([], $variants->getChildSkus(['', '   ']));
        $this->assertSame([], $variants->getVariantOptionIds([]));
        $this->assertSame([], $variants->getVariantLabels([]));
    }

    public function testSkusAreMatchedCaseInsensitivelyAndReturnedAsAsked(): void
    {
        $this->givenACatalogue();

        $ids = $this->variants()->getVariantOptionIds(['shirt-ceil-s']);

        $this->assertArrayHasKey('shirt-ceil-s', $ids);
    }

    /**
     * Three selects: children with their parents, the parents' axes, then the
     * children's values.
     *
     * @param string $backendType The backend type the axes report.
     */
    private function givenACatalogue(string $backendType = 'int'): void
    {
        $this->rows[0] = [
            ['child_sku' => 'SHIRT-CEIL-S', 'child_link' => '100', 'parent_link' => '1'],
            ['child_sku' => 'SHIRT-WINE-M', 'child_link' => '101', 'parent_link' => '1'],
        ];
        $this->rows[1] = [
            ['parent_link' => '1', 'attribute_id' => '93', 'attribute_code' => 'color', 'backend_type' => $backendType],
            ['parent_link' => '1', 'attribute_id' => '94', 'attribute_code' => 'size', 'backend_type' => $backendType],
        ];
        $this->rows[2] = [
            ['link_id' => '100', 'attribute_id' => '93', 'value' => '247'],
            ['link_id' => '100', 'attribute_id' => '94', 'value' => '10'],
            ['link_id' => '101', 'attribute_id' => '93', 'value' => '248'],
            ['link_id' => '101', 'attribute_id' => '94', 'value' => '11'],
        ];
    }

    private function variants(): ConfigurableVariants
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturnCallback(fn (): string => $this->linkField);

        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')
            ->willReturnCallback(function (string $entity) use ($metadata): EntityMetadataInterface {
                $this->assertSame(ProductInterface::class, $entity);

                return $metadata;
            });

        $stagedEntityFilter = new StagedEntityFilter($this->resourceConnection, $metadataPool);

        return new ConfigurableVariants(
            $this->resourceConnection,
            $stagedEntityFilter,
            new VariantReader($this->resourceConnection, $stagedEntityFilter),
            $this->optionLabels,
            $this->memo
        );
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

        $select->method('from')
            ->willReturnCallback(function ($table, $columns = '*') use ($select, $index): Select {
                $this->tables[$index] = is_array($table) ? (string) reset($table) : (string) $table;

                return $select;
            });
        $select->method('join')
            ->willReturnCallback(function ($name, $condition, $columns = '*') use ($select, $index): Select {
                $alias = is_array($name) ? (string) array_key_first($name) : (string) $name;

                $this->joins[$index][$alias] = (string) $condition;

                return $select;
            });
        $select->method('where')
            ->willReturnCallback(function (string $condition, $value = null) use ($select, $index): Select {
                $this->conditions[$index][] = [$condition, $value];

                return $select;
            });
        $select->method('order')->willReturnSelf();

        $this->selects[$index] = $select;

        return $select;
    }
}
