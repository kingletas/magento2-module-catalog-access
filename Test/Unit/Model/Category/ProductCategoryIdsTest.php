<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Test\Unit\Model\Category;

use Kingletas\CatalogAccess\Api\StoreScopeInterface;
use Kingletas\CatalogAccess\Model\Category\ProductCategoryIds;
use Kingletas\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductCategoryIdsTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;
    private TableMaintainer&MockObject $tableMaintainer;
    private RequestMemo $memo;

    /** @var array<int, Select&MockObject> */
    private array $selects = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $rows = [];

    /** @var array<int, string> The table each select was built against. */
    private array $tables = [];

    /** @var array<int, array<int, array{0: string, 1: mixed}>> */
    private array $conditions = [];

    protected function setUp(): void
    {
        $this->selects = [];
        $this->rows = [];
        $this->tables = [];
        $this->conditions = [];

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->newSelect());
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

        $this->tableMaintainer = $this->createMock(TableMaintainer::class);
        $this->tableMaintainer->method('getMainTable')
            ->willReturnCallback(static fn (int $storeId): string => 'catalog_category_product_index_store' . $storeId);

        $this->memo = new RequestMemo(100);
    }

    public function testReturnsTheCategoriesOfEveryProduct(): void
    {
        $this->rows[0] = [
            ['product_id' => '10', 'category_id' => '3'],
            ['product_id' => '10', 'category_id' => '7'],
            ['product_id' => '11', 'category_id' => '3'],
        ];

        $this->assertSame(
            [10 => [3, 7], 11 => [3]],
            $this->resolver()->getAssignedCategoryIds([10, 11])
        );
    }

    /**
     * Answering one product per call, from a quote item modifier, costs a query
     * per cart line on every totals collection.
     */
    public function testAWholeCartIsOneQuery(): void
    {
        $this->connection->expects($this->once())->method('fetchAll');

        $this->resolver()->getAssignedCategoryIds(range(1, 40));
    }

    public function testAssignedReadsTheAssignmentTable(): void
    {
        $this->resolver()->getAssignedCategoryIds([10]);

        $this->assertSame(['catalog_category_product'], $this->tables);
    }

    /**
     * "Assigned" and "visible" are different questions on an anchored
     * catalogue.
     */
    public function testVisibleReadsTheStoresIndexTable(): void
    {
        $this->resolver()->getVisibleCategoryIds([10], 4);

        $this->assertSame(['catalog_category_product_index_store4'], $this->tables);
    }

    public function testVisibleWithoutAStoreUsesTheCurrentOne(): void
    {
        $this->resolver()->getVisibleCategoryIds([10]);

        $this->assertSame(['catalog_category_product_index_store1'], $this->tables);
    }

    public function testTheTwoQuestionsDoNotShareAnAnswer(): void
    {
        $this->rows[0] = [['product_id' => '10', 'category_id' => '3']];
        $this->rows[1] = [
            ['product_id' => '10', 'category_id' => '3'],
            ['product_id' => '10', 'category_id' => '2'],
        ];

        $resolver = $this->resolver();

        $this->assertSame([10 => [3]], $resolver->getAssignedCategoryIds([10]));
        $this->assertSame(
            [10 => [3, 2]],
            $resolver->getVisibleCategoryIds([10], 1),
            'The anchor ancestor is in the index and not in the assignments.'
        );
    }

    public function testEachStoresIndexIsRememberedSeparately(): void
    {
        $this->rows[0] = [['product_id' => '10', 'category_id' => '3']];
        $this->rows[1] = [['product_id' => '10', 'category_id' => '9']];

        $resolver = $this->resolver();

        $this->assertSame([10 => [3]], $resolver->getVisibleCategoryIds([10], 1));
        $this->assertSame([10 => [9]], $resolver->getVisibleCategoryIds([10], 2));
        $this->assertSame([10 => [3]], $resolver->getVisibleCategoryIds([10], 1));
    }

    public function testTheSameProductIsAskedAboutOnce(): void
    {
        $this->rows[0] = [['product_id' => '10', 'category_id' => '3']];

        $this->connection->expects($this->once())->method('fetchAll');

        $resolver = $this->resolver();

        $resolver->getAssignedCategoryIds([10]);
        $resolver->getAssignedCategoryIds([10]);
    }

    /**
     * A memo that stores a result only when it is non-empty re-queries every
     * uncategorised product every time it is asked about.
     */
    public function testAnUncategorisedProductIsAlsoRemembered(): void
    {
        $this->connection->expects($this->once())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertSame([], $resolver->getAssignedCategoryIds([10]));
        $this->assertSame([], $resolver->getAssignedCategoryIds([10]));
    }

    public function testOnlyTheProductsNotYetKnownAreQueriedAgain(): void
    {
        $this->rows[0] = [['product_id' => '10', 'category_id' => '3']];
        $this->rows[1] = [['product_id' => '11', 'category_id' => '4']];

        $resolver = $this->resolver();
        $resolver->getAssignedCategoryIds([10]);
        $resolver->getAssignedCategoryIds([10, 11]);

        $this->assertContains(['product_id IN (?)', [11]], $this->conditions[1]);
    }

    public function testCategoriesComeBackInAdminSortOrder(): void
    {
        $this->rows[0] = [
            ['product_id' => '10', 'category_id' => '7'],
            ['product_id' => '10', 'category_id' => '3'],
        ];

        $this->assertSame(
            [10 => [7, 3]],
            $this->resolver()->getAssignedCategoryIds([10]),
            'The order the query returned, which is ordered by position.'
        );
    }

    public function testTheSameCategoryCannotAppearTwice(): void
    {
        $this->rows[0] = [
            ['product_id' => '10', 'category_id' => '3'],
            ['product_id' => '10', 'category_id' => '3'],
        ];

        $this->assertSame([10 => [3]], $this->resolver()->getAssignedCategoryIds([10]));
    }

    public function testLongListsAreChunked(): void
    {
        $this->resolver(chunkSize: 2)->getAssignedCategoryIds([1, 2, 3, 4, 5]);

        $this->assertCount(3, $this->selects);
    }

    public function testNothingToResolveIsNotAQuery(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertSame([], $resolver->getAssignedCategoryIds([]));
        $this->assertSame([], $resolver->getAssignedCategoryIds([0, -2]));
        $this->assertSame([], $resolver->getVisibleCategoryIds([]));
    }

    public function testResultsAreKeyedInTheOrderAsked(): void
    {
        $this->rows[0] = [
            ['product_id' => '11', 'category_id' => '3'],
            ['product_id' => '10', 'category_id' => '4'],
        ];

        $this->assertSame([10, 11], array_keys($this->resolver()->getAssignedCategoryIds([10, 11])));
    }

    private function resolver(int $chunkSize = 1000): ProductCategoryIds
    {
        $storeScope = $this->createMock(StoreScopeInterface::class);
        $storeScope->method('getCurrentStoreId')->willReturn(1);

        return new ProductCategoryIds(
            $this->resourceConnection,
            $this->tableMaintainer,
            $storeScope,
            $this->memo,
            $chunkSize
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

        $select->method('from')
            ->willReturnCallback(function ($table, $columns = '*') use ($select, $index): Select {
                $this->tables[$index] = is_array($table) ? (string) reset($table) : (string) $table;

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
