<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Test\Unit;

use Kingletas\Foundation\Test\Support\DiWiringAssertions;
use PHPUnit\Framework\TestCase;

/**
 * This module's `di.xml` against its own constructors.
 *
 * @see DiWiringAssertions for what each assertion reads and why.
 */
class DiWiringTest extends TestCase
{
    use DiWiringAssertions;

    public function testEveryInjectedInterfaceCanBeResolvedByTheObjectManager(): void
    {
        $this->assertEveryInjectedInterfaceIsResolvable($this->moduleDir());
    }

    public function testEveryPreferenceNamesAClassThatExistsAndImplementsIt(): void
    {
        $this->assertEveryPreferenceResolvesToAnImplementation($this->moduleDir());
    }

    public function testNoVirtualTypeIsReferencedThroughAGeneratedProxy(): void
    {
        $this->assertNoVirtualTypeIsReferencedThroughAGeneratedProxy($this->moduleDir());
    }

    private function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
