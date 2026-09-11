<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Test\Unit\Model\Store;

use Kingletas\CatalogAccess\Model\Store\StoreScope;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StoreScopeTest extends TestCase
{
    private StoreManagerInterface&MockObject $storeManager;
    private Emulation&MockObject $emulation;
    private DesignInterface&MockObject $design;
    private StoreScope $scope;

    /** @var array<int, string> Ordered log of emulation start/stop calls. */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->emulation = $this->createMock(Emulation::class);
        $this->design = $this->createMock(DesignInterface::class);

        $this->design->method('getArea')->willReturn(Area::AREA_FRONTEND);

        $this->emulation->method('startEnvironmentEmulation')
            ->willReturnCallback(function ($storeId, $area = Area::AREA_FRONTEND, $force = false): void {
                $this->calls[] = 'start:' . $storeId . ':' . $area;
            });

        $this->emulation->method('stopEnvironmentEmulation')
            ->willReturnCallback(function (): Emulation {
                $this->calls[] = 'stop';

                return $this->emulation;
            });

        $this->currentStoreIs(1);

        $this->scope = new StoreScope($this->storeManager, $this->emulation, $this->design);
    }

    public function testReturnsWhateverTheCallbackReturns(): void
    {
        $this->assertSame('result', $this->scope->run(2, static fn (): string => 'result'));
    }

    public function testPassesTheResolvedStoreIdToTheCallback(): void
    {
        $seen = null;

        $this->scope->run(2, static function (int $storeId) use (&$seen): void {
            $seen = $storeId;
        });

        $this->assertSame(2, $seen);
    }

    /**
     * The defect this class exists for.
     */
    public function testEmulationIsStoppedWhenTheCallbackThrows(): void
    {
        try {
            $this->scope->run(2, static function (): void {
                throw new RuntimeException('boom');
            });

            $this->fail('The exception should propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['start:2:frontend', 'stop'], $this->calls);
    }

    public function testDoesNotEmulateTheStoreItIsAlreadyIn(): void
    {
        $this->scope->run(1, static fn (): bool => true);

        $this->assertSame([], $this->calls);
    }

    /**
     * Same store, different area, is not the same environment.
     */
    public function testEmulatesTheCurrentStoreWhenTheAreaDiffers(): void
    {
        $this->scope->run(1, static fn (): bool => true, Area::AREA_ADMINHTML);

        $this->assertSame(['start:1:adminhtml', 'stop'], $this->calls);
    }

    public function testANullStoreIdRunsInPlace(): void
    {
        $seen = null;

        $this->scope->run(null, static function (int $storeId) use (&$seen): void {
            $seen = $storeId;
        });

        $this->assertSame([], $this->calls);
        $this->assertSame(1, $seen, 'A null store means the current one, and the callback is told which that is.');
    }

    /**
     * Magento permits one level of emulation and silently returns from the
     * second — the framework's own log line saying so is commented out.
     */
    public function testANestedScopeReallyEntersTheInnerStore(): void
    {
        $inner = null;

        $this->scope->run(2, function () use (&$inner): void {
            $this->calls[] = 'outer-work';

            $this->scope->run(3, function (int $storeId) use (&$inner): void {
                $inner = $storeId;
                $this->calls[] = 'inner-work';
            });

            $this->calls[] = 'outer-work-again';
        });

        $this->assertSame(3, $inner);
        $this->assertSame(
            [
                'start:2:frontend',
                'outer-work',
                'stop',
                'start:3:frontend',
                'inner-work',
                'stop',
                'start:2:frontend',
                'outer-work-again',
                'stop',
            ],
            $this->calls,
            'Leaving the inner scope has to restore the enclosing store, not the one the process started in.'
        );
    }

    public function testANestedScopeForTheSameStoreDoesNotReEnterIt(): void
    {
        $this->scope->run(2, function (): void {
            $this->scope->run(2, function (): void {
                $this->calls[] = 'inner-work';
            });
        });

        $this->assertSame(['start:2:frontend', 'inner-work', 'stop'], $this->calls);
    }

    public function testRunForEachEntersAndLeavesEachStoreInTurn(): void
    {
        $results = $this->scope->runForEach([2, 3], static fn (int $storeId): string => 'store-' . $storeId);

        $this->assertSame([2 => 'store-2', 3 => 'store-3'], $results);
        $this->assertSame(
            ['start:2:frontend', 'stop', 'start:3:frontend', 'stop'],
            $this->calls,
            'Stores are visited in sequence, never nested: whatever one does, the next starts clean.'
        );
    }

    public function testGetCurrentStoreIdFallsBackToAdminWhenThereIsNoCurrentStore(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')
            ->willThrowException(new NoSuchEntityException(__('No store')));

        $scope = new StoreScope($storeManager, $this->emulation, $this->design);

        $this->assertSame(0, $scope->getCurrentStoreId());
    }

    private function currentStoreIs(int $storeId): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);

        $this->storeManager->method('getStore')->willReturn($store);
    }
}
