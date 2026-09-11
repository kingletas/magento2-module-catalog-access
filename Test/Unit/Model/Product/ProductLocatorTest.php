<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Test\Unit\Model\Product;

use Kingletas\CatalogAccess\Api\StoreScopeInterface;
use Kingletas\CatalogAccess\Model\Memo\RequestMemo;
use Kingletas\CatalogAccess\Model\Product\ProductLocator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductLocatorTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $repository;
    private StoreScopeInterface&MockObject $storeScope;
    private RequestMemo $memo;
    private ProductLocator $locator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProductRepositoryInterface::class);
        $this->storeScope = $this->createMock(StoreScopeInterface::class);
        $this->storeScope->method('getCurrentStoreId')->willReturn(1);
        $this->memo = new RequestMemo(100);

        $this->locator = new ProductLocator($this->repository, $this->storeScope, $this->memo);
    }

    public function testReturnsTheProduct(): void
    {
        $product = $this->product('SKU-1', 10);
        $this->repository->method('get')->willReturn($product);

        $this->assertSame($product, $this->locator->findBySku('SKU-1'));
    }

    /**
     * The repository answers "does this SKU exist" with an exception, which
     * `find` does not.
     */
    public function testAMissingSkuIsNullRatherThanAnException(): void
    {
        $this->repository->method('get')
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->assertNull($this->locator->findBySku('GONE'));
    }

    /**
     * Only `NoSuchEntityException` is caught; anything else would turn a broken
     * catalogue empty.
     */
    public function testOtherFailuresAreNotSwallowed(): void
    {
        $this->repository->method('get')
            ->willThrowException(new LocalizedException(__('attribute backend is broken')));

        $this->expectException(LocalizedException::class);

        $this->locator->findBySku('SKU-1');
    }

    public function testGetBySkuThrowsWithTheSkuInTheMessage(): void
    {
        $this->repository->method('get')
            ->willThrowException(new NoSuchEntityException(__('gone')));

        try {
            $this->locator->getBySku('SKU-404');

            $this->fail('Expected a NoSuchEntityException.');
        } catch (NoSuchEntityException $e) {
            $this->assertStringContainsString('SKU-404', $e->getMessage());
        }
    }

    public function testTheSameSkuIsLoadedOnce(): void
    {
        $this->repository->expects($this->once())
            ->method('get')
            ->willReturn($this->product('SKU-1', 10));

        $this->locator->findBySku('SKU-1');
        $this->locator->findBySku('SKU-1');
        $this->locator->findBySku('SKU-1');
    }

    /**
     * A miss is an answer too.
     */
    public function testAMissIsRememberedAsWell(): void
    {
        $this->repository->expects($this->once())
            ->method('get')
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->assertNull($this->locator->findBySku('GONE'));
        $this->assertNull($this->locator->findBySku('GONE'));
    }

    /**
     * The memo is keyed by store because the product is scoped by store: name,
     * price, status and visibility all differ.
     */
    public function testProductsAreMemoisedPerStore(): void
    {
        $storeOne = $this->product('SKU-1', 10);
        $storeTwo = $this->product('SKU-1', 10);

        $this->repository->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(
                static fn (string $sku, bool $edit, ?int $storeId): ProductInterface
                    => $storeId === 2 ? $storeTwo : $storeOne
            );

        $this->assertSame($storeOne, $this->locator->findBySku('SKU-1', 1));
        $this->assertSame($storeTwo, $this->locator->findBySku('SKU-1', 2));
        $this->assertSame($storeOne, $this->locator->findBySku('SKU-1', 1));
    }

    /**
     * "Current" is resolved on every call, not once.
     */
    public function testANullStoreFollowsTheCurrentStoreAsItChanges(): void
    {
        $storeScope = $this->createMock(StoreScopeInterface::class);
        $storeScope->method('getCurrentStoreId')->willReturnOnConsecutiveCalls(1, 2);

        $storeOne = $this->product('SKU-1', 10);
        $storeTwo = $this->product('SKU-1', 10);

        $this->repository->method('get')
            ->willReturnCallback(
                static fn (string $sku, bool $edit, ?int $storeId): ProductInterface
                    => $storeId === 2 ? $storeTwo : $storeOne
            );

        $locator = new ProductLocator($this->repository, $storeScope, $this->memo);

        $this->assertSame($storeOne, $locator->findBySku('SKU-1'));
        $this->assertSame($storeTwo, $locator->findBySku('SKU-1'));
    }

    public function testTheStoreIdIsPassedToTheRepository(): void
    {
        $this->repository->expects($this->once())
            ->method('get')
            ->with('SKU-1', false, 7)
            ->willReturn($this->product('SKU-1', 10));

        $this->locator->findBySku('SKU-1', 7);
    }

    public function testABlankSkuIsNotAskedAbout(): void
    {
        $this->repository->expects($this->never())->method('get');

        $this->assertNull($this->locator->findBySku('   '));
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $this->repository->expects($this->once())
            ->method('get')
            ->with('SKU-1', false, 1)
            ->willReturn($this->product('SKU-1', 10));

        $this->locator->findBySku(" SKU-1\n");
    }

    public function testAProductLoadedBySkuIsAlsoFoundById(): void
    {
        $product = $this->product('SKU-1', 10);

        $this->repository->expects($this->once())->method('get')->willReturn($product);
        $this->repository->expects($this->never())->method('getById');

        $this->locator->findBySku('SKU-1');

        $this->assertSame($product, $this->locator->findById(10));
    }

    public function testAProductLoadedByIdIsAlsoFoundBySku(): void
    {
        $product = $this->product('SKU-1', 10);

        $this->repository->expects($this->once())->method('getById')->willReturn($product);
        $this->repository->expects($this->never())->method('get');

        $this->locator->findById(10);

        $this->assertSame($product, $this->locator->findBySku('SKU-1'));
    }

    public function testAnIdOfZeroIsNotAskedAbout(): void
    {
        $this->repository->expects($this->never())->method('getById');

        $this->assertNull($this->locator->findById(0));
    }

    public function testGetByIdThrowsWithTheIdInTheMessage(): void
    {
        $this->repository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('gone')));

        try {
            $this->locator->getById(4242);

            $this->fail('Expected a NoSuchEntityException.');
        } catch (NoSuchEntityException $e) {
            $this->assertStringContainsString('4242', $e->getMessage());
        }
    }

    /**
     * A memo that outlives a write is the mechanism behind "the value I just
     * saved is not what I read back".
     */
    public function testForgetDropsTheProductInEveryStoreAndUnderBothKeys(): void
    {
        $product = $this->product('SKU-1', 10);

        $this->repository->method('get')->willReturn($product);
        $this->repository->method('getById')->willReturn($product);

        $this->locator->findBySku('SKU-1', 1);
        $this->locator->findBySku('SKU-1', 2);
        $this->locator->findById(10, 1);

        $this->assertGreaterThan(0, $this->memo->count());

        $this->locator->forget('SKU-1');

        $this->assertSame(0, $this->memo->count(), 'Nothing about this product may survive its save.');
    }

    /**
     * SKU lookups are case-insensitive in Magento, so forgetting has to be too.
     */
    public function testForgetIsCaseInsensitive(): void
    {
        $this->repository->method('get')->willReturn($this->product('SKU-1', 10));

        $this->locator->findBySku('SKU-1');
        $this->locator->forget('sku-1');

        $this->assertSame(0, $this->memo->count());
    }

    /**
     * A SKU created during the request has to become visible; the remembered
     * "no such product" is exactly what would hide it.
     */
    public function testForgetAlsoDropsARememberedMiss(): void
    {
        $this->repository->expects($this->exactly(2))
            ->method('get')
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->locator->findBySku('NEW-SKU');
        $this->locator->forget('NEW-SKU');
        $this->locator->findBySku('NEW-SKU');
    }

    public function testForgetLeavesOtherProductsAlone(): void
    {
        $one = $this->product('SKU-1', 10);
        $two = $this->product('SKU-2', 11);

        $this->repository->method('get')
            ->willReturnCallback(static fn (string $sku): ProductInterface => $sku === 'SKU-1' ? $one : $two);

        $this->locator->findBySku('SKU-1');
        $this->locator->findBySku('SKU-2');

        $this->locator->forget('SKU-1');

        $this->assertGreaterThan(0, $this->memo->count());
        $this->assertSame($two, $this->locator->findBySku('SKU-2'));
    }

    public function testClearEmptiesTheMemo(): void
    {
        $this->repository->method('get')->willReturn($this->product('SKU-1', 10));

        $this->locator->findBySku('SKU-1');
        $this->locator->clear();

        $this->assertSame(0, $this->memo->count());
    }

    /**
     * The bound is what keeps a consumer that runs for hours inside one PHP
     * process from ending on the memory limit half way through its output.
     */
    public function testTheMemoStaysBounded(): void
    {
        $memo = new RequestMemo(4);
        $locator = new ProductLocator($this->repository, $this->storeScope, $memo);

        $this->repository->method('get')
            ->willReturnCallback(fn (string $sku): ProductInterface => $this->product($sku, 0));

        foreach (range(1, 200) as $i) {
            $locator->findBySku('SKU-' . $i);
        }

        $this->assertLessThanOrEqual(4, $memo->count());
    }

    private function product(string $sku, int $id): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getId')->willReturn($id === 0 ? null : $id);

        return $product;
    }
}
