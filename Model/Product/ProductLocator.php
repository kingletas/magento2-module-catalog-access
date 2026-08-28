<?php
/**
 * ProductLocator.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Model\Product;

use Commerce\CatalogAccess\Api\ProductLocatorInterface;
use Commerce\CatalogAccess\Api\StoreScopeInterface;
use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Repository-backed, so what comes out is a real product entity — extension
 * attributes, type instance and all.
 *
 * @see ProductLocatorInterface
 */
class ProductLocator implements ProductLocatorInterface
{
    private const KEY_SKU = 'sku';
    private const KEY_ID = 'id';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreScopeInterface $storeScope,
        private readonly RequestMemo $memo
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findBySku(string $sku, ?int $storeId = null): ?ProductInterface
    {
        $sku = trim($sku);

        if ($sku === '') {
            // An empty SKU is data rather than an error, and the honest answer
            // is no product.
            return null;
        }

        $storeId = $this->resolveStoreId($storeId);
        $key = $this->key(self::KEY_SKU, $sku, $storeId);

        if ($this->memo->has($key)) {
            return $this->memo->get($key);
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            // Only this one.
            $product = null;
        }

        return $this->remember($sku, $storeId, $product);
    }

    /**
     * @inheritDoc
     */
    public function getBySku(string $sku, ?int $storeId = null): ProductInterface
    {
        $product = $this->findBySku($sku, $storeId);

        if ($product === null) {
            throw new NoSuchEntityException(
                __('No product with SKU "%1" exists in store %2.', $sku, $this->resolveStoreId($storeId))
            );
        }

        return $product;
    }

    /**
     * @inheritDoc
     */
    public function findById(int $productId, ?int $storeId = null): ?ProductInterface
    {
        if ($productId <= 0) {
            return null;
        }

        $storeId = $this->resolveStoreId($storeId);
        $key = $this->key(self::KEY_ID, (string) $productId, $storeId);

        if ($this->memo->has($key)) {
            return $this->memo->get($key);
        }

        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
        } catch (NoSuchEntityException) {
            $product = null;
        }

        $this->memo->set($key, $product);

        if ($product !== null) {
            // Also reachable by SKU now, so the same product loaded twice by
            // two different call sites is still one load.
            $this->memo->set($this->key(self::KEY_SKU, (string) $product->getSku(), $storeId), $product);
        }

        return $product;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $productId, ?int $storeId = null): ProductInterface
    {
        $product = $this->findById($productId, $storeId);

        if ($product === null) {
            throw new NoSuchEntityException(
                __('No product with id %1 exists in store %2.', $productId, $this->resolveStoreId($storeId))
            );
        }

        return $product;
    }

    /**
     * @inheritDoc
     */
    public function forget(string ...$skus): void
    {
        $targets = [];

        foreach ($skus as $sku) {
            $sku = trim($sku);

            if ($sku !== '') {
                // SKU lookups are case-insensitive in Magento, so forgetting
                // has to be too.
                $targets[] = mb_strtolower($sku);
            }
        }

        if ($targets === []) {
            return;
        }

        // Every store and both key kinds.
        $this->memo->forgetMatching(
            static function (string $key, mixed $value) use ($targets): bool {
                if ($value instanceof ProductInterface) {
                    return in_array(mb_strtolower((string) $value->getSku()), $targets, true);
                }

                $parts = explode("\0", $key);

                // A memoised "no such SKU" has to go too: without it, a SKU
                // created during the request stays invisible for the rest of
                // it.
                return ($parts[0] ?? '') === self::KEY_SKU
                    && in_array(mb_strtolower($parts[1] ?? ''), $targets, true);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $this->memo->clear();
    }

    private function remember(string $sku, int $storeId, ?ProductInterface $product): ?ProductInterface
    {
        $this->memo->set($this->key(self::KEY_SKU, $sku, $storeId), $product);

        if ($product !== null && $product->getId() !== null) {
            $this->memo->set($this->key(self::KEY_ID, (string) $product->getId(), $storeId), $product);
        }

        return $product;
    }

    /**
     * Resolve "current" now rather than storing null as if it were a store.
     */
    private function resolveStoreId(?int $storeId): int
    {
        return $storeId ?? $this->storeScope->getCurrentStoreId();
    }

    /**
     * NUL-delimited so that a SKU containing the separator cannot be made to
     * collide with another key.
     */
    private function key(string $kind, string $identifier, int $storeId): string
    {
        return $kind . "\0" . $identifier . "\0" . $storeId;
    }
}
