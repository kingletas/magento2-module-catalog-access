<?php
/**
 * ProductBatchLoaderTest.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Product;

use Commerce\CatalogAccess\Api\StoreScopeInterface;
use Commerce\CatalogAccess\Model\Product\ProductBatchLoader;
use Commerce\Foundation\Api\ConfigurableParentSkuResolverInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductBatchLoaderTest extends TestCase
{
    private CollectionFactory&MockObject $collectionFactory;
    private ConfigurableParentSkuResolverInterface&MockObject $parentSkuResolver;
    private StoreScopeInterface&MockObject $storeScope;

    /** @var array<int, Collection&MockObject> Collections handed out, in order. */
    private array $collections = [];

    /** @var array<int, array<int, mixed>> What each collection was filtered on. */
    private array $filters = [];

    /** @var array<int, ProductInterface> Products the next collection will contain. */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $this->collections = [];
        $this->filters = [];
        $this->catalogue = [];

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')
            ->willReturnCallback(fn (): Collection => $this->newCollection());

        $this->parentSkuResolver = $this->createMock(ConfigurableParentSkuResolverInterface::class);

        $this->storeScope = $this->createMock(StoreScopeInterface::class);
        $this->storeScope->method('getCurrentStoreId')->willReturn(1);
    }

    public function testLoadsEveryRequestedSku(): void
    {
        $this->catalogue = [$this->product('SKU-1', 10), $this->product('SKU-2', 11)];

        $found = $this->loader()->loadBySkus(['SKU-1', 'SKU-2']);

        $this->assertSame(['SKU-1', 'SKU-2'], array_keys($found));
        $this->assertCount(1, $this->collections, 'One collection, not one per SKU.');
    }

    /**
     * The point of the class.
     */
    public function testAHundredSkusAreOneQuery(): void
    {
        $skus = array_map(static fn (int $i): string => 'SKU-' . $i, range(1, 100));
        $this->catalogue = array_map(fn (string $sku): ProductInterface => $this->product($sku, 0), $skus);

        $this->loader()->loadBySkus($skus);

        $this->assertCount(1, $this->collections);
    }

    /**
     * An `IN` list is not free.
     */
    public function testLongListsAreChunked(): void
    {
        $skus = array_map(static fn (int $i): string => 'SKU-' . $i, range(1, 5));

        $this->loader(chunkSize: 2)->loadBySkus($skus);

        $this->assertCount(3, $this->collections);
        $this->assertSame([['SKU-1', 'SKU-2'], ['SKU-3', 'SKU-4'], ['SKU-5']], array_map(
            static fn (array $filter): array => $filter[1]['in'],
            $this->filters
        ));
    }

    /**
     * Magento's default collation is case-insensitive, so a collection asked
     * for `abc-1` comes back holding `ABC-1`.
     */
    public function testResultsAreKeyedBySkuAsRequestedNotAsStored(): void
    {
        $this->catalogue = [$this->product('ABC-1', 10)];

        $found = $this->loader()->loadBySkus(['abc-1']);

        $this->assertArrayHasKey('abc-1', $found);
        $this->assertSame('ABC-1', $found['abc-1']->getSku());
    }

    public function testDuplicatesAndBlanksAreIgnored(): void
    {
        $this->loader()->loadBySkus(['SKU-1', ' SKU-1 ', 'sku-1', '', '   ']);

        $this->assertSame([['SKU-1']], array_map(
            static fn (array $filter): array => $filter[1]['in'],
            $this->filters
        ));
    }

    public function testNothingToLoadIsNotAQuery(): void
    {
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertSame([], $this->loader()->loadBySkus(['', '  ']));
        $this->assertSame([], $this->loader()->loadByIds([0, -1]));
    }

    public function testMissingSkusAreSimplyAbsent(): void
    {
        $this->catalogue = [$this->product('SKU-1', 10)];

        $found = $this->loader()->loadBySkus(['SKU-1', 'GONE']);

        $this->assertSame(['SKU-1'], array_keys($found));
    }

    public function testTheStoreIsSetOnTheCollection(): void
    {
        $collection = null;

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$collection): Collection {
                $collection = $this->newCollection();
                $collection->expects($this->once())->method('setStoreId')->with(7);

                return $collection;
            }
        );

        $this->loader()->loadBySkus(['SKU-1'], 7);

        $this->assertNotNull($collection);
    }

    public function testANullStoreMeansTheCurrentOne(): void
    {
        $collection = null;

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$collection): Collection {
                $collection = $this->newCollection();
                $collection->expects($this->once())->method('setStoreId')->with(1);

                return $collection;
            }
        );

        $this->loader()->loadBySkus(['SKU-1']);

        $this->assertNotNull($collection);
    }

    /**
     * `Magento\CatalogInventory\Model\AddStockStatusToCollection` is a
     * `beforeLoad` plugin registered in the frontend area only.
     */
    public function testTheImplicitFrontendStockFilterIsTurnedOff(): void
    {
        $collection = null;

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$collection): Collection {
                $collection = $this->newCollection();
                $collection->expects($this->once())
                    ->method('setFlag')
                    ->with('has_stock_status_filter', true);

                return $collection;
            }
        );

        $this->loader()->loadBySkus(['SKU-1']);

        $this->assertNotNull($collection);
    }

    /**
     * `addStoreFilter()` would hide a product the store does not sell as though
     * it were deleted.
     */
    public function testTheResultIsNotNarrowedToTheStoresWebsite(): void
    {
        $collection = null;

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$collection): Collection {
                $collection = $this->newCollection();
                $collection->expects($this->never())->method('addStoreFilter');

                return $collection;
            }
        );

        $this->loader()->loadBySkus(['SKU-1']);

        $this->assertNotNull($collection);
    }

    /**
     * Only the string form of `*` means every attribute; the array form names
     * an attribute `*`.
     */
    public function testSelectingEveryAttributeUsesTheFormMagentoUnderstands(): void
    {
        $collection = null;

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$collection): Collection {
                $collection = $this->newCollection();
                $collection->expects($this->once())->method('addAttributeToSelect')->with('*');

                return $collection;
            }
        );

        $this->loader()->loadBySkus(['SKU-1']);

        $this->assertNotNull($collection);
    }

    public function testAttributesGivenAtTheCallSiteWin(): void
    {
        $collection = null;

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$collection): Collection {
                $collection = $this->newCollection();
                $collection->expects($this->once())
                    ->method('addAttributeToSelect')
                    ->with(['name', 'price']);

                return $collection;
            }
        );

        $this->loader()->loadBySkus(['SKU-1'], null, ['name', 'price']);

        $this->assertNotNull($collection);
    }

    public function testLoadByIdsIsKeyedByIntegerId(): void
    {
        $this->catalogue = [$this->product('SKU-1', 10), $this->product('SKU-2', 11)];

        $found = $this->loader()->loadByIds(['10', 11, 10]);

        $this->assertSame([10, 11], array_keys($found));
        $this->assertSame([[10, 11]], $this->filters);
    }

    public function testParentsAreResolvedThenLoadedOnce(): void
    {
        $this->parentSkuResolver->method('resolveMany')->willReturn([
            'CHILD-A' => 'PARENT-1',
            'CHILD-B' => 'PARENT-1',
            'CHILD-C' => 'PARENT-2',
        ]);

        $parentOne = $this->product('PARENT-1', 1);
        $this->catalogue = [$parentOne, $this->product('PARENT-2', 2)];

        $found = $this->loader()->loadParentsBySkus(['CHILD-A', 'CHILD-B', 'CHILD-C']);

        $this->assertSame(['CHILD-A', 'CHILD-B', 'CHILD-C'], array_keys($found));
        $this->assertSame($parentOne, $found['CHILD-A']);
        $this->assertSame($parentOne, $found['CHILD-B'], 'Two children of one configurable share one loaded parent.');
        $this->assertCount(1, $this->collections);
        $this->assertSame([['PARENT-1', 'PARENT-2']], array_map(
            static fn (array $filter): array => $filter[1]['in'],
            $this->filters
        ));
    }

    public function testAChildWithNoParentIsAbsentRatherThanFatal(): void
    {
        $this->parentSkuResolver->method('resolveMany')->willReturn(['CHILD-A' => 'PARENT-1']);
        $this->catalogue = [];

        $found = $this->loader()->loadParentsBySkus(['CHILD-A', 'STANDALONE']);

        $this->assertSame([], $found);
    }

    public function testNoParentsMeansNoProductQuery(): void
    {
        $this->parentSkuResolver->method('resolveMany')->willReturn([]);
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertSame([], $this->loader()->loadParentsBySkus(['STANDALONE']));
    }

    /**
     * `loadBySkus()` returns everything, which is right for a cart and out of
     * memory for an export.
     */
    public function testEachBySkusHandsOverOneChunkAtATime(): void
    {
        $this->catalogue = [$this->product('SKU-1', 10), $this->product('SKU-2', 11)];

        $chunks = [];
        $handed = $this->loader(chunkSize: 2)->eachBySkus(
            ['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4'],
            static function (array $products) use (&$chunks): void {
                $chunks[] = array_keys($products);
            }
        );

        $this->assertCount(2, $chunks, 'One call per chunk, not one per product and not one at the end.');
        $this->assertSame(['SKU-1', 'SKU-2'], $chunks[0]);
        $this->assertSame(4, $handed);
    }

    public function testEachBySkusKeysChunksTheWayLoadBySkusKeysItsResult(): void
    {
        $this->catalogue = [$this->product('ABC-1', 10)];

        $seen = [];
        $this->loader()->eachBySkus(['abc-1'], static function (array $products) use (&$seen): void {
            $seen = $products;
        });

        $this->assertArrayHasKey('abc-1', $seen);
    }

    public function testEachBySkusIsNotCalledWithAnEmptyChunk(): void
    {
        $this->catalogue = [];

        $calls = 0;
        $handed = $this->loader()->eachBySkus(['GONE'], static function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame(0, $calls);
        $this->assertSame(0, $handed);
    }

    /**
     * A half-written export should stop, not carry on quietly.
     */
    public function testEachBySkusLetsTheCallbacksFailurePropagate(): void
    {
        $this->catalogue = [$this->product('SKU-1', 10)];

        $this->expectException(RuntimeException::class);

        $this->loader()->eachBySkus(['SKU-1'], static function (): void {
            throw new RuntimeException('disk full');
        });
    }

    private function loader(int $chunkSize = 500): ProductBatchLoader
    {
        return new ProductBatchLoader(
            $this->collectionFactory,
            $this->parentSkuResolver,
            $this->storeScope,
            ['*'],
            $chunkSize
        );
    }

    /**
     * @return Collection&MockObject
     */
    private function newCollection(): Collection
    {
        $collection = $this->createMock(Collection::class);

        $collection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, $condition) use ($collection): Collection {
                $this->filters[] = [$field, $condition];

                return $collection;
            });

        $collection->method('addIdFilter')
            ->willReturnCallback(function ($ids) use ($collection): Collection {
                $this->filters[] = $ids;

                return $collection;
            });

        $collection->method('getItems')->willReturnCallback(fn (): array => $this->catalogue);

        $this->collections[] = $collection;

        return $collection;
    }

    private function product(string $sku, int $id): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
