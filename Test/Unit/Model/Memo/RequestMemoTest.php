<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Memo;

use Commerce\CatalogAccess\Model\Memo\RequestMemo;
use PHPUnit\Framework\TestCase;

class RequestMemoTest extends TestCase
{
    public function testStoresAndReturnsValues(): void
    {
        $memo = new RequestMemo();
        $memo->set('a', 'first');

        $this->assertTrue($memo->has('a'));
        $this->assertSame('first', $memo->get('a'));
        $this->assertFalse($memo->has('b'));
        $this->assertNull($memo->get('b'));
    }

    /**
     * The distinction the whole negative-caching story rests on: "we looked and
     * there is nothing" has to be tellable from "we have not looked".
     */
    public function testAStoredNullIsNotTheSameAsAnAbsentKey(): void
    {
        $memo = new RequestMemo();
        $memo->set('missing-sku', null);

        $this->assertTrue($memo->has('missing-sku'));
        $this->assertNull($memo->get('missing-sku'));
        $this->assertFalse($memo->has('never-asked'));
    }

    public function testNeverGrowsPastItsLimit(): void
    {
        $memo = new RequestMemo(3);

        foreach (range(1, 100) as $i) {
            $memo->set('key-' . $i, $i);
        }

        $this->assertSame(3, $memo->count());
        $this->assertSame(100, $memo->get('key-100'));
        $this->assertFalse($memo->has('key-1'));
    }

    /**
     * The reason for LRU rather than insertion order: a feed touching a hundred
     * thousand products asks about the same few categories throughout.
     */
    public function testEvictsTheLeastRecentlyUsedEntryNotTheOldest(): void
    {
        $memo = new RequestMemo(3);
        $memo->set('hot', 'kept');
        $memo->set('cold', 'evicted');
        $memo->set('filler', 1);

        // Reading "hot" makes it the most recent, so the next insert has to
        // take "cold" instead.
        $this->assertSame('kept', $memo->get('hot'));

        $memo->set('new', 2);

        $this->assertTrue($memo->has('hot'));
        $this->assertFalse($memo->has('cold'));
    }

    public function testAHitThroughHasCountsAsUse(): void
    {
        $memo = new RequestMemo(2);
        $memo->set('a', 1);
        $memo->set('b', 2);

        $this->assertTrue($memo->has('a'));

        $memo->set('c', 3);

        $this->assertTrue($memo->has('a'), 'has() is a read, so it must refresh recency the way get() does.');
        $this->assertFalse($memo->has('b'));
    }

    public function testRewritingAKeyDoesNotDuplicateIt(): void
    {
        $memo = new RequestMemo(2);
        $memo->set('a', 1);
        $memo->set('a', 2);
        $memo->set('b', 3);

        $this->assertSame(2, $memo->count());
        $this->assertSame(2, $memo->get('a'));
        $this->assertSame(3, $memo->get('b'));
    }

    /**
     * A limit of zero would behave like a disabled memo with nothing in the log
     * to say so.
     */
    public function testALimitBelowOneIsClampedRatherThanHonoured(): void
    {
        $memo = new RequestMemo(0);
        $memo->set('a', 1);

        $this->assertSame(1, $memo->getLimit());
        $this->assertSame(1, $memo->get('a'));
    }

    public function testForgetRemovesOneKeyAndClearRemovesAll(): void
    {
        $memo = new RequestMemo();
        $memo->set('a', 1);
        $memo->set('b', 2);

        $memo->forget('a');

        $this->assertFalse($memo->has('a'));
        $this->assertTrue($memo->has('b'));

        $memo->clear();

        $this->assertSame(0, $memo->count());
    }

    public function testForgetMatchingSeesBothKeyAndValue(): void
    {
        $memo = new RequestMemo();
        $memo->set('sku:A', ['sku' => 'A']);
        $memo->set('id:7', ['sku' => 'A']);
        $memo->set('sku:B', ['sku' => 'B']);

        $memo->forgetMatching(
            static fn (string $key, mixed $value): bool => ($value['sku'] ?? null) === 'A'
        );

        $this->assertFalse($memo->has('sku:A'));
        $this->assertFalse($memo->has('id:7'), 'The entry reached by id has to go with the one reached by SKU.');
        $this->assertTrue($memo->has('sku:B'));
    }
}
