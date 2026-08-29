<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Performance;

use Commerce\CatalogAccess\Api\StoreScopeInterface;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Commerce\CatalogAccess\Model\Product\ProductBatchLoader;
use Commerce\CatalogAccess\Model\Product\ProductLocator;
use Commerce\Foundation\Api\ConfigurableParentSkuResolverInterface;
use Commerce\Foundation\Test\Support\BudgetAssertions;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

/**
 * What reading the catalogue costs, as the amount being read grows.
 */
class CatalogueReadCostTest extends TestCase
{
    use BudgetAssertions;

    private int $collections = 0;

    /** @var ProductInterface[] Whatever the next collection will contain. */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $this->collections = 0;
        $this->catalogue = [];
    }

    /**
     * The call site is a loop over order lines or feed rows.
     */
    public function testLoadingManySkusCostsTheSameAsLoadingOne(): void
    {
        $this->assertConstantCost(
            'queries while loading products by SKU',
            function (int $skus): int {
                $this->collections = 0;
                $this->catalogue = $this->products($skus);

                $this->loader()->loadBySkus($this->skus($skus));

                return $this->collections;
            }
        );
    }

    /**
     * Past the chunk size it is one query per chunk, and not one per SKU.
     */
    public function testAnOversizedBatchIsChunkedRatherThanSplitPerSku(): void
    {
        $this->assertCostPerBatch(
            'queries while loading a catalogue-sized batch',
            100,
            function (int $skus): int {
                $this->collections = 0;
                $this->catalogue = $this->products($skus);

                $this->loader(chunkSize: 100)->loadBySkus($this->skus($skus));

                return $this->collections;
            },
            [100, 250, 1000]
        );
    }

    /**
     * The difference from `loadBySkus` is not the query count: the chunk is
     * dropped rather than accumulated.
     */
    public function testStreamingACatalogueHoldsOneChunkAtATime(): void
    {
        $this->catalogue = $this->products(1000);
        $largestBatchHandedOver = 0;

        $handed = $this->loader(chunkSize: 100)->eachBySkus(
            $this->skus(1000),
            function (array $products) use (&$largestBatchHandedOver): void {
                $largestBatchHandedOver = max($largestBatchHandedOver, count($products));
            }
        );

        $this->assertSame(1000, $handed, 'Every SKU should still reach the callback.');
        $this->assertLessThanOrEqual(
            100,
            $largestBatchHandedOver,
            'The callback was handed more than a chunk, so the chunks are being accumulated first.'
        );
    }

    /**
     * Four modules resolve products on a single product save, and several of
     * them ask about the same SKU.
     */
    public function testTheSameProductIsLoadedOncePerRequest(): void
    {
        $loads = 0;
        $locator = $this->locator($loads);

        $locator->findBySku('SHIRT', 1);
        $locator->findBySku('SHIRT', 1);
        $locator->findBySku('SHIRT', 1);

        $this->assertSame(1, $loads, 'The memo should have answered the second and third asks.');
    }

    /**
     * Import rows name retired SKUs, and they name them repeatedly.
     */
    public function testAKnownAbsenceIsNotLookedUpAgain(): void
    {
        $loads = 0;
        $locator = $this->locator($loads);

        $locator->findBySku('DELETED-SKU', 1);
        $locator->findBySku('DELETED-SKU', 1);

        $this->assertSame(1, $loads);
    }

    /**
     * The bound that turns a memory leak into a cache: a consumer runs for
     * hours in one process, and an unbounded memo ends the job half-written.
     */
    public function testTheMemoNeverGrowsPastItsLimit(): void
    {
        $memo = new RequestMemo(100);

        for ($i = 0; $i < 10_000; $i++) {
            $memo->set('sku_' . $i, ['a row of product data', $i]);
        }

        $this->assertSame(100, $memo->count(), 'An unbounded memo is how a long-running consumer runs out of memory.');
    }

    /**
     * Eviction is least-recently-used, so a long feed does not throw the
     * categories away.
     */
    public function testTheMemoKeepsWhatIsStillBeingAskedFor(): void
    {
        $memo = new RequestMemo(10);
        $memo->set('hot', 'a category name everything needs');

        for ($i = 0; $i < 100; $i++) {
            $memo->set('cold_' . $i, 'a product nobody asks about twice');
            // Asked for on every iteration, so it is never the least recent.
            $memo->get('hot');
        }

        $this->assertTrue($memo->has('hot'), 'The entry asked for on every iteration was evicted anyway.');
    }

    private function loader(int $chunkSize = 500): ProductBatchLoader
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(fn (): Collection => $this->newCollection());

        $storeScope = $this->createMock(StoreScopeInterface::class);
        $storeScope->method('getCurrentStoreId')->willReturn(1);

        return new ProductBatchLoader(
            $collectionFactory,
            $this->createMock(ConfigurableParentSkuResolverInterface::class),
            $storeScope,
            ['*'],
            $chunkSize
        );
    }

    /**
     * A collection that answers with whatever slice of the catalogue its filter
     * asked for, and counts itself as one query.
     */
    private function newCollection(): Collection
    {
        $this->collections++;
        $wanted = [];

        $collection = $this->createMock(Collection::class);
        $collection->method('setStore')->willReturnSelf();
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addStoreFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('setFlag')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$wanted, $collection): Collection {
                foreach ((array) ($condition['in'] ?? []) as $sku) {
                    $wanted[mb_strtolower((string) $sku)] = true;
                }

                return $collection;
            }
        );
        $collection->method('getItems')->willReturnCallback(
            function () use (&$wanted): array {
                $items = [];

                foreach ($this->catalogue as $product) {
                    if ($wanted === [] || isset($wanted[mb_strtolower((string) $product->getSku())])) {
                        $items[] = $product;
                    }
                }

                return $items;
            }
        );

        return $collection;
    }

    private function locator(int &$loads): ProductLocator
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $sku) use (&$loads): ProductInterface {
                $loads++;

                if ($sku === 'DELETED-SKU') {
                    throw new NoSuchEntityException(new Phrase('no such product'));
                }

                return $this->product($sku);
            }
        );

        $storeScope = $this->createMock(StoreScopeInterface::class);
        $storeScope->method('getCurrentStoreId')->willReturn(1);

        return new ProductLocator($repository, $storeScope, new RequestMemo());
    }

    /**
     * @return string[]
     */
    private function skus(int $count): array
    {
        $skus = [];

        for ($i = 1; $i <= $count; $i++) {
            $skus[] = 'SHIRT-' . $i;
        }

        return $skus;
    }

    /**
     * @return ProductInterface[]
     */
    private function products(int $count): array
    {
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $products[] = $this->product('SHIRT-' . $i);
        }

        return $products;
    }

    private function product(string $sku): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getId')->willReturn(1);

        return $product;
    }
}
