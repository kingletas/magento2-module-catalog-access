<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Test\Wiring;

use Kingletas\Foundation\Test\Support\ModuleWiringTestCase;

/**
 * This module's `etc/` against the code it names.
 */
class WiringTest extends ModuleWiringTestCase
{
    protected function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
