<?php
/**
 * StoreScopedReadTest.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Behaviour;

use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Commerce\CatalogAccess\Model\Product\ProductLocator;
use Commerce\CatalogAccess\Model\Store\StoreScope;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading the catalogue in another store, and coming back.
 */
class StoreScopedReadTest extends TestCase
{
    /** @var array<int, string> Emulation calls, in order. */
    private array $emulation = [];

    private int $currentStoreId = 1;

    /** @var array<string, array<int, ProductInterface>> SKU => store id => product. */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $this->emulation = [];
        $this->currentStoreId = 1;
        $this->catalogue = [];
    }

    public function testAScopedBlockRunsInItsStoreAndLeavesTheProcessWhereItFoundIt(): void
    {
        $seen = $this->scope()->run(2, static fn (int $storeId): int => $storeId);

        $this->assertSame(2, $seen);
        $this->assertSame(['start:2:frontend', 'stop'], $this->emulation);
    }

    /**
     * An exception inside a scoped read must not leave emulation running for
     * later queries.
     */
    public function testAFailingBlockStillRestoresTheEnvironment(): void
    {
        try {
            $this->scope()->run(2, static function (): void {
                throw new RuntimeException('the callback failed');
            });
            $this->fail('The exception should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('the callback failed', $e->getMessage());
        }

        $this->assertSame(['start:2:frontend', 'stop'], $this->emulation);
    }

    public function testLeavingANestedScopeReturnsToTheEnclosingStore(): void
    {
        $scope = $this->scope();
        $storesSeenAfterTheInnerBlock = [];

        $scope->run(2, function (int $outer) use ($scope, &$storesSeenAfterTheInnerBlock): void {
            $scope->run(3, static fn (int $inner): int => $inner);

            // Back in store 2's block.
            $storesSeenAfterTheInnerBlock[] = $outer;
        });

        $this->assertSame([2], $storesSeenAfterTheInnerBlock);
        $this->assertSame(
            [
                'start:2:frontend',
                // The outer scope is stood down before the inner starts,
                // because Magento ignores a second start without complaint.
                'stop',
                'start:3:frontend',
                // Leaving the inner one restores the outer explicitly.
                'stop',
                'start:2:frontend',
                'stop',
            ],
            $this->emulation
        );
    }

    /**
     * Re-entering the store already in force costs a design, locale and
     * translation load.
     */
    public function testAskingForTheStoreAlreadyInUseDoesNotEmulate(): void
    {
        $seen = $this->scope()->run(1, static fn (int $storeId): int => $storeId);

        $this->assertSame(1, $seen);
        $this->assertSame([], $this->emulation, 'Store 1 is already the current store.');
    }

    /**
     * A per-store export is the call site.
     */
    public function testASweepEntersAndLeavesEachStoreInTurn(): void
    {
        $results = $this->scope()->runForEach([2, 3], static fn (int $storeId): string => 'ran in ' . $storeId);

        $this->assertSame([2 => 'ran in 2', 3 => 'ran in 3'], $results);
        $this->assertSame(
            ['start:2:frontend', 'stop', 'start:3:frontend', 'stop'],
            $this->emulation
        );
    }

    /**
     * Skipping it renders admin URLs into a customer's email, which is a defect
     * nobody sees until a customer replies asking what the link is.
     */
    public function testTheAreaIsPartOfWhetherAScopeIsAlreadyActive(): void
    {
        $this->scope(currentArea: Area::AREA_ADMINHTML)->run(1, static fn (int $s): int => $s);

        $this->assertSame(['start:1:frontend', 'stop'], $this->emulation);
    }

    /**
     * `ProductLocator` keys its memo on the store `StoreScope` reports.
     */
    public function testTheSameSkuInTwoStoresIsTwoAnswers(): void
    {
        $this->catalogue['SHIRT'] = [
            1 => $this->product('SHIRT', 'Scrub Top'),
            2 => $this->product('SHIRT', 'Blouse de bloc'),
        ];

        $locator = $this->locator();

        $this->assertSame('Scrub Top', $locator->findBySku('SHIRT', 1)?->getName());
        $this->assertSame('Blouse de bloc', $locator->findBySku('SHIRT', 2)?->getName());
    }

    /**
     * Order lines and import rows name products that have been deleted.
     */
    public function testAMissingSkuIsAnAnswerRatherThanAnException(): void
    {
        $locator = $this->locator();

        $this->assertNull($locator->findBySku('DELETED-SKU', 1));

        $this->expectException(NoSuchEntityException::class);
        $locator->getBySku('DELETED-SKU', 1);
    }

    private function scope(string $currentArea = Area::AREA_FRONTEND): StoreScope
    {
        $store = new DataObject(['id' => $this->currentStoreId]);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(
            function ($storeId, $area = Area::AREA_FRONTEND) use ($emulation) {
                $this->emulation[] = sprintf('start:%d:%s', (int) $storeId, (string) $area);

                return $emulation;
            }
        );
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(
            function () use ($emulation) {
                $this->emulation[] = 'stop';

                return $emulation;
            }
        );

        $design = $this->createMock(DesignInterface::class);
        $design->method('getArea')->willReturn($currentArea);

        return new StoreScope($storeManager, $emulation, $design);
    }

    private function locator(): ProductLocator
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $sku, bool $editMode = false, ?int $storeId = null): ProductInterface {
                $product = $this->catalogue[$sku][(int) $storeId] ?? null;

                if ($product === null) {
                    throw new NoSuchEntityException(new Phrase('no such product'));
                }

                return $product;
            }
        );

        return new ProductLocator($repository, $this->scope(), new RequestMemo());
    }

    private function product(string $sku, string $name): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);

        return $product;
    }
}
