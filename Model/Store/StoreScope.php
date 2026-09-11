<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Store;

use Kingletas\CatalogAccess\Api\StoreScopeInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Emulation with a `finally`, and a stack so that nesting means what it says.
 *
 * @see StoreScopeInterface for the two failure modes this exists to remove.
 */
class StoreScope implements StoreScopeInterface
{
    /**
     * Stores currently emulated, innermost last.
     *
     * @var array<int, array{storeId: int, area: string}>
     */
    private array $stack = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly DesignInterface $design
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(?int $storeId, callable $callback, string $area = Area::AREA_FRONTEND): mixed
    {
        if ($storeId === null) {
            return $callback($this->getCurrentStoreId());
        }

        if ($this->isAlreadyScoped($storeId, $area)) {
            return $callback($storeId);
        }

        // Magento allows one level and silently ignores the second, so an
        // enclosing emulation has to be stood down before the inner one starts.
        if ($this->activeScope() !== null) {
            $this->emulation->stopEnvironmentEmulation();
        }

        $this->emulation->startEnvironmentEmulation($storeId, $area, true);
        $this->stack[] = ['storeId' => $storeId, 'area' => $area];

        try {
            return $callback($storeId);
        } finally {
            $this->leaveScope();
        }
    }

    /**
     * Whether the environment is already the one asked for, so re-entering it
     * can be skipped.
     */
    private function isAlreadyScoped(int $storeId, string $area): bool
    {
        $current = $this->activeScope();

        if ($current !== null) {
            return $current['storeId'] === $storeId && $current['area'] === $area;
        }

        // Not emulating, so the ambient environment decides.
        return $storeId === $this->getCurrentStoreId() && $area === $this->design->getArea();
    }

    /**
     * Leave the innermost scope and put the enclosing one back.
     */
    private function leaveScope(): void
    {
        array_pop($this->stack);
        $this->emulation->stopEnvironmentEmulation();

        $enclosing = $this->activeScope();

        if ($enclosing !== null) {
            $this->emulation->startEnvironmentEmulation(
                $enclosing['storeId'],
                $enclosing['area'],
                true
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function runForEach(array $storeIds, callable $callback, string $area = Area::AREA_FRONTEND): array
    {
        $results = [];

        foreach ($storeIds as $storeId) {
            $storeId = (int) $storeId;

            // Each store is entered and left in turn rather than nesting:
            // whatever the callback does, the next store starts from a clean
            // environment.
            $results[$storeId] = $this->run($storeId, $callback, $area);
        }

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function getCurrentStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            // No current store: a CLI process before an area is set, or a
            // consumer started outside a request.
            return (int) Store::DEFAULT_STORE_ID;
        }
    }

    /**
     * @return array{storeId: int, area: string}|null
     */
    private function activeScope(): ?array
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}
