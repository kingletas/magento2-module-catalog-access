<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Model\Memo;

/**
 * A bounded, least-recently-used memo for the length of one request.
 */
class RequestMemo
{
    /** @var array<string, mixed> Insertion order is LRU order, oldest first. */
    private array $entries = [];

    private int $limit;

    /**
     * @param int $limit Maximum entries. Clamped to at least one: a memo that
     *                   can hold nothing is a subtle way to turn every read
     *                   into a query while still looking configured.
     */
    public function __construct(int $limit = 1000)
    {
        $this->limit = max(1, $limit);
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->entries)) {
            return false;
        }

        $this->touch($key);

        return true;
    }

    /**
     * @return mixed The stored value, or null when absent — use `has()` to
     *               tell a stored null from an absent key.
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->entries)) {
            return null;
        }

        $this->touch($key);

        return $this->entries[$key];
    }

    public function set(string $key, mixed $value): void
    {
        unset($this->entries[$key]);

        $this->entries[$key] = $value;

        $overflow = count($this->entries) - $this->limit;

        for ($trimmed = 0; $trimmed < $overflow; $trimmed++) {
            $oldest = array_key_first($this->entries);

            if ($oldest === null) {
                break;
            }

            unset($this->entries[$oldest]);
        }
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    /**
     * Drop every entry the predicate accepts, given its key and its value.
     *
     * @param callable(string, mixed): bool $predicate
     */
    public function forgetMatching(callable $predicate): void
    {
        foreach ($this->entries as $key => $value) {
            if ($predicate($key, $value)) {
                unset($this->entries[$key]);
            }
        }
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    private function touch(string $key): void
    {
        $value = $this->entries[$key];

        unset($this->entries[$key]);

        $this->entries[$key] = $value;
    }
}
