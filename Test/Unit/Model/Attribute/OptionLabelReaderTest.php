<?php
/**
 * @package   Commerce_CatalogAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Attribute;

use Commerce\CatalogAccess\Model\Attribute\OptionLabelReader;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean as BooleanSource;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two ways a label is read, and the choice between them.
 */
class OptionLabelReaderTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private OptionLabelReader $reader;

    /** @var array<int, array<string, mixed>> Rows the one select returns. */
    private array $rows = [];

    protected function setUp(): void
    {
        $this->rows = [];

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturnCallback(fn (): array => $this->rows);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->reader = new OptionLabelReader($resourceConnection);
    }

    /**
     * A text, date or price attribute has no options.
     */
    public function testAnAttributeWithNoSourceResolvesToNothing(): void
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('usesSource')->willReturn(false);

        $this->assertSame([], $this->reader->labelsFor($attribute, [12, 47], 1));
    }

    public function testATableSourcedAttributeIsReadFromTheOptionValueTables(): void
    {
        $this->rows = [
            ['option_id' => '12', 'label' => 'Petite'],
            ['option_id' => '47', 'label' => 'Tall'],
        ];

        $this->assertSame(
            [12 => 'Petite', 47 => 'Tall'],
            $this->reader->labelsFor($this->attributeWithSource($this->createMock(TableSource::class)), [12, 47], 1)
        );
    }

    /**
     * A blank store-scoped label is not a label.
     */
    public function testABlankLabelIsOmittedRatherThanIndexedEmpty(): void
    {
        $this->rows = [
            ['option_id' => '12', 'label' => ''],
            ['option_id' => '47', 'label' => 'Tall'],
        ];

        $this->assertSame(
            [47 => 'Tall'],
            $this->reader->labelsFor($this->attributeWithSource($this->createMock(TableSource::class)), [12, 47], 1)
        );
    }

    /**
     * Status, yes/no and anything a module supplies have no rows in the option
     * tables, so they are asked of the source model instead.
     */
    public function testASourceModelledAttributeIsAskedForItsOptions(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willReturn([
            ['value' => 1, 'label' => 'Enabled'],
            ['value' => 2, 'label' => 'Disabled'],
        ]);

        $this->assertSame(
            [1 => 'Enabled', 2 => 'Disabled'],
            $this->reader->labelsFor($this->attributeWithSource($source), [1, 2], 1)
        );
    }

    /**
     * Optgroups nest their options one level down.
     */
    public function testNestedOptgroupOptionsAreFlattened(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willReturn([
            ['label' => 'Warm', 'value' => [['value' => 7, 'label' => 'Crimson']]],
            ['value' => 9, 'label' => 'Slate'],
        ]);

        $this->assertSame(
            [7 => 'Crimson', 9 => 'Slate'],
            $this->reader->labelsFor($this->attributeWithSource($source), [7, 9], 1)
        );
    }

    /**
     * A custom source model reaching for a request, a registry or a table that
     * is not there yet.
     */
    public function testASourceModelThatBlowsUpResolvesToNothing(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willThrowException(new RuntimeException('no registry here'));

        $this->assertSame([], $this->reader->labelsFor($this->attributeWithSource($source), [1], 1));
    }

    public function testOnlyTheOptionIdsAskedForComeBack(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willReturn([
            ['value' => 1, 'label' => 'Enabled'],
            ['value' => 2, 'label' => 'Disabled'],
        ]);

        $this->assertSame([2 => 'Disabled'], $this->reader->labelsFor($this->attributeWithSource($source), [2], 1));
    }

    private function attributeWithSource(object $source): AbstractAttribute&MockObject
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);

        return $attribute;
    }
}
