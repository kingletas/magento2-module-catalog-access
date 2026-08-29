<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Api;

use Magento\Framework\App\Area;

/**
 * Runs a callback in another store's environment and always puts the
 * environment back.
 */
interface StoreScopeInterface
{
    /**
     * @param int|null $storeId  Null, or the current store, runs the callback
     *                           as-is — emulation is not free and pretending
     *                           to emulate the store you are already in is the
     *                           expensive way to do nothing.
     * @param callable $callback Receives the resolved store id.
     *
     * @return mixed Whatever the callback returns. Exceptions propagate; the
     *               environment is restored first.
     */
    public function run(?int $storeId, callable $callback, string $area = Area::AREA_FRONTEND): mixed;

    /**
     * Run the same callback once per store.
     *
     * @param int[] $storeIds
     *
     * @return array<int, mixed> Store id => return value, in the order given.
     */
    public function runForEach(array $storeIds, callable $callback, string $area = Area::AREA_FRONTEND): array;

    /**
     * The current store id, including inside an emulated block.
     *
     * @return int Falls back to the admin store (0) when there is no current
     *             store — which is the honest answer in a CLI process, and is
     *             what `getStore()` would have thrown about.
     */
    public function getCurrentStoreId(): int;
}
