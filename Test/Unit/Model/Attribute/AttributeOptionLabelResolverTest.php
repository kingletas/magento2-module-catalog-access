<?php
/**
 * @package   Kingletas_CatalogAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\CatalogAccess\Test\Unit\Model\Attribute;

use Kingletas\CatalogAccess\Api\StoreScopeInterface;
use Kingletas\CatalogAccess\Model\Attribute\AttributeOptionLabelResolver;
use Kingletas\CatalogAccess\Model\Attribute\OptionLabelReader;
use Kingletas\CatalogAccess\Model\Memo\RequestMemo;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean as BooleanSource;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AttributeOptionLabelResolverTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private EavConfig&MockObject $eavConfig;
    private ResourceConnection&MockObject $resourceConnection;
    private RequestMemo $memo;

    /** @var array<int, array<int, array<string, mixed>>> Rows each select returns. */
    private array $rows = [];

    /** @var array<int, Select&MockObject> */
    private array $selects = [];

    /** @var array<int, array<string, array{condition: string, columns: mixed}>> */
    private array $joins = [];

    /** @var array<int, array<int, array{0: string, 1: mixed}>> */
    private array $conditions = [];

    protected function setUp(): void
    {
        $this->rows = [];
        $this->selects = [];
        $this->joins = [];
        $this->conditions = [];

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->newSelect());
        $this->connection->method('fetchAll')
            ->willReturnCallback(function (Select $select): array {
                foreach ($this->selects as $index => $candidate) {
                    if ($candidate === $select) {
                        return $this->rows[$index] ?? [];
                    }
                }

                return [];
            });

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => $table);

        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->memo = new RequestMemo(100);
    }

    public function testReturnsLabelsForOptionIds(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->rows[0] = [
            ['option_id' => '247', 'label' => 'Ceil Blue'],
            ['option_id' => '248', 'label' => 'Wine'],
        ];

        $this->assertSame(
            [247 => 'Ceil Blue', 248 => 'Wine'],
            $this->resolver()->getLabels('color', [247, 248])
        );
    }

    public function testAWholeBatchIsOneQuery(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->connection->expects($this->once())->method('fetchAll');

        $this->resolver()->getLabels('color', range(1, 40));
    }

    public function testTheStoreLabelWinsAndTheDefaultOneIsTheFallback(): void
    {
        $this->attributeIs($this->tableSourced());

        $this->resolver()->getLabels('color', [247], 4);

        $this->assertStringContainsString('store_value.store_id = 4', $this->joins[0]['store_value']['condition']);
        $this->assertSame(
            'COALESCE(store_value.value, default_value.value)',
            (string) $this->joins[0]['store_value']['columns']['label']
        );
        $this->assertContains(['default_value.store_id = ?', 0], $this->conditions[0]);
    }

    /**
     * Labels are read here rather than from the source model, which caches them
     * per attribute.
     */
    public function testATableSourcedAttributeIsNeitherAskedNorMutated(): void
    {
        $source = $this->createMock(TableSource::class);
        $source->expects($this->never())->method('getAllOptions');

        $attribute = $this->attributeIs($source);
        $attribute->expects($this->never())->method('__call');

        $this->resolver()->getLabels('color', [247], 4);
    }

    /**
     * Status, yes/no and anything a module supplies have no rows in the option
     * tables at all.
     */
    public function testAnAttributeWithARealSourceModelIsAskedForItsOptions(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willReturn([
            ['value' => 1, 'label' => 'Yes'],
            ['value' => 0, 'label' => 'No'],
        ]);

        $this->attributeIs($source);
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([1 => 'Yes'], $this->resolver()->getLabels('is_featured', [1]));
    }

    /**
     * Zero is a real option value on a yes/no attribute.
     */
    public function testZeroIsAnOptionValueAndNotAnAbsentOne(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willReturn([
            ['value' => 1, 'label' => 'Yes'],
            ['value' => 0, 'label' => 'No'],
        ]);

        $this->attributeIs($source);

        $this->assertSame('No', $this->resolver()->getLabel('is_featured', 0));
    }

    public function testOptionsGroupedIntoOptgroupsAreFlattened(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willReturn([
            [
                'label' => 'Cool tones',
                'value' => [
                    ['value' => 5, 'label' => 'Ceil Blue'],
                    ['value' => 6, 'label' => 'Teal'],
                ],
            ],
            ['value' => 7, 'label' => 'Wine'],
        ]);

        $this->attributeIs($source);

        $this->assertSame([5 => 'Ceil Blue', 7 => 'Wine'], $this->resolver()->getLabels('color', [5, 7]));
    }

    /**
     * A source model reaching for something absent must not take the caller
     * down.
     */
    public function testAFailingSourceModelCostsTheLabelAndNothingElse(): void
    {
        $source = $this->createMock(BooleanSource::class);
        $source->method('getAllOptions')->willThrowException(new RuntimeException('no registry here'));

        $this->attributeIs($source);

        $this->assertSame([], $this->resolver()->getLabels('is_featured', [1]));
    }

    public function testAnAttributeWithNoOptionsResolvesToNothing(): void
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(1);
        $attribute->method('usesSource')->willReturn(false);

        $this->eavConfig->method('getAttribute')->willReturn($attribute);
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->resolver()->getLabels('description', [247]));
    }

    /**
     * An unknown code comes back from `Eav\Model\Config` as an empty attribute,
     * not an exception.
     */
    public function testAnUnknownAttributeIsNotQueriedAround(): void
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(null);

        $this->eavConfig->method('getAttribute')->willReturn($attribute);
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->resolver()->getLabels('not_an_attribute', [247]));
    }

    public function testAnAttributeLookupThatThrowsIsNotFatal(): void
    {
        $this->eavConfig->method('getAttribute')
            ->willThrowException(new LocalizedException(__('no such entity type')));

        $this->assertSame([], $this->resolver()->getLabels('color', [247]));
    }

    /**
     * A multiselect stores "12,47" in one column and a select stores "12".
     */
    public function testAMultiselectValueResolvesToEveryLabel(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->rows[0] = [
            ['option_id' => '12', 'label' => 'Petite'],
            ['option_id' => '47', 'label' => 'Tall'],
        ];

        $this->assertSame(['Petite', 'Tall'], $this->resolver()->resolveValue('fit', '12,47'));
    }

    public function testAnArrayOfIdsResolvesTheSameWay(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->rows[0] = [
            ['option_id' => '12', 'label' => 'Petite'],
            ['option_id' => '47', 'label' => 'Tall'],
        ];

        $this->assertSame(['Petite', 'Tall'], $this->resolver()->resolveValue('fit', [12, 47]));
    }

    public function testAnEmptyValueResolvesToAnEmptyListAndNotToNull(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->connection->expects($this->never())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertSame([], $resolver->resolveValue('fit', null));
        $this->assertSame([], $resolver->resolveValue('fit', ''));
        $this->assertSame([], $resolver->resolveValue('fit', 'not-an-id'));
        $this->assertNull($resolver->getLabel('fit', null));
        $this->assertNull($resolver->getLabel('fit', ''));
    }

    public function testTheSameOptionIsAskedAboutOnce(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->rows[0] = [['option_id' => '247', 'label' => 'Ceil Blue']];

        $this->connection->expects($this->once())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertSame('Ceil Blue', $resolver->getLabel('color', 247));
        $this->assertSame('Ceil Blue', $resolver->getLabel('color', 247));
        $this->assertSame([247 => 'Ceil Blue'], $resolver->getLabels('color', [247]));
    }

    public function testEachStoreIsRememberedSeparately(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->rows[0] = [['option_id' => '247', 'label' => 'Ceil Blue']];
        $this->rows[1] = [['option_id' => '247', 'label' => 'Bleu Ciel']];

        $resolver = $this->resolver();

        $this->assertSame('Ceil Blue', $resolver->getLabel('color', 247, 1));
        $this->assertSame('Bleu Ciel', $resolver->getLabel('color', 247, 2));
        $this->assertSame('Ceil Blue', $resolver->getLabel('color', 247, 1));
    }

    public function testAnUnknownOptionIsAskedAboutOnce(): void
    {
        $this->attributeIs($this->tableSourced());

        $this->connection->expects($this->once())->method('fetchAll');

        $resolver = $this->resolver();

        $this->assertNull($resolver->getLabel('color', 999));
        $this->assertNull($resolver->getLabel('color', 999));
    }

    public function testLabelsComeBackInTheOrderTheyWereAskedFor(): void
    {
        $this->attributeIs($this->tableSourced());
        $this->rows[0] = [
            ['option_id' => '47', 'label' => 'Tall'],
            ['option_id' => '12', 'label' => 'Petite'],
        ];

        $this->assertSame([12 => 'Petite', 47 => 'Tall'], $this->resolver()->getLabels('fit', [12, 47, 12]));
    }

    private function resolver(): AttributeOptionLabelResolver
    {
        $storeScope = $this->createMock(StoreScopeInterface::class);
        $storeScope->method('getCurrentStoreId')->willReturn(1);

        return new AttributeOptionLabelResolver(
            $this->eavConfig,
            new OptionLabelReader($this->resourceConnection),
            $storeScope,
            $this->memo
        );
    }

    private function tableSourced(): TableSource&MockObject
    {
        return $this->createMock(TableSource::class);
    }

    /**
     * @return AbstractAttribute&MockObject
     */
    private function attributeIs(object $source): AbstractAttribute
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(93);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);

        $this->eavConfig->method('getAttribute')->willReturn($attribute);

        return $attribute;
    }

    /**
     * @return Select&MockObject
     */
    private function newSelect(): Select
    {
        $index = count($this->selects);
        $select = $this->createMock(Select::class);

        $this->joins[$index] = [];
        $this->conditions[$index] = [];

        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')
            ->willReturnCallback(function ($name, $condition, $columns) use ($select, $index): Select {
                $alias = is_array($name) ? (string) array_key_first($name) : (string) $name;

                $this->joins[$index][$alias] = ['condition' => (string) $condition, 'columns' => $columns];

                return $select;
            });
        $select->method('where')
            ->willReturnCallback(function (string $condition, $value = null) use ($select, $index): Select {
                $this->conditions[$index][] = [$condition, $value];

                return $select;
            });

        $this->selects[$index] = $select;

        return $select;
    }
}
