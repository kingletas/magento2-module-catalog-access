<?php
/**
 * AttributeValueReaderTest.php
 *
 * @package     Commerce_CatalogAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CatalogAccess\Test\Unit\Model\Attribute;

use Commerce\CatalogAccess\Api\AttributeOptionLabelResolverInterface;
use Commerce\CatalogAccess\Model\Attribute\AttributeValueReader;
use Magento\Framework\DataObject;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AttributeValueReaderTest extends TestCase
{
    private AttributeOptionLabelResolverInterface&MockObject $optionLabels;
    private AttributeValueReader $reader;

    protected function setUp(): void
    {
        $this->optionLabels = $this->createMock(AttributeOptionLabelResolverInterface::class);
        $this->reader = new AttributeValueReader($this->optionLabels);
    }

    /**
     * The `strpos(): Passing null` and `trim(null)` patches this module's
     * estate has accumulated are all this line, written at the call site.
     */
    public function testAMissingValueIsAStringAndNotNull(): void
    {
        $entity = new DataObject(['url' => null]);

        self::assertSame('', $this->reader->getString($entity, 'url'));
        self::assertSame('', $this->reader->getString($entity, 'never_set'));
        self::assertSame('placeholder', $this->reader->getString($entity, 'url', 'placeholder'));
    }

    public function testAStringComesBackUnchanged(): void
    {
        $entity = new DataObject(['name' => 'Ceil Blue Scrub Top']);

        self::assertSame('Ceil Blue Scrub Top', $this->reader->getString($entity, 'name'));
    }

    public function testNumbersAreStringifiedRatherThanRefused(): void
    {
        $entity = new DataObject(['position' => 12, 'price' => 19.99]);

        self::assertSame('12', $this->reader->getString($entity, 'position'));
        self::assertSame('19.99', $this->reader->getString($entity, 'price'));
    }

    /**
     * Magento returns Phrases wherever a label or a message is involved, and a
     * Phrase is Stringable rather than a string.
     */
    public function testAPhraseIsReadAsItsText(): void
    {
        $entity = new DataObject(['label' => new Phrase('Out of stock')]);

        self::assertSame('Out of stock', $this->reader->getString($entity, 'label'));
    }

    /**
     * Casting a multiselect to a string is how a feed column comes to contain
     * the literal word "Array" — which is a support ticket, not an error.
     */
    public function testAnArrayIsNotSilentlyStringified(): void
    {
        $entity = new DataObject(['fit' => ['Petite', 'Tall']]);

        self::assertSame('', $this->reader->getString($entity, 'fit'));
        self::assertSame('n/a', $this->reader->getString($entity, 'fit', 'n/a'));
        self::assertStringNotContainsString('Array', $this->reader->getString($entity, 'fit'));
    }

    /**
     * A product loaded with a narrow attribute set answers null for everything
     * it did not select.
     */
    public function testAbsentIsTellableFromEmpty(): void
    {
        $entity = new DataObject(['description' => '']);

        self::assertTrue($this->reader->has($entity, 'description'));
        self::assertFalse($this->reader->has($entity, 'short_description'));
        self::assertNull($this->reader->getStringOrNull($entity, 'description'));
        self::assertNull($this->reader->getStringOrNull($entity, 'short_description'));
    }

    public function testIntegersFallBackRatherThanCollapsingToZero(): void
    {
        $entity = new DataObject(['qty' => '14', 'note' => 'not a number', 'zero' => '0']);

        self::assertSame(14, $this->reader->getInt($entity, 'qty'));
        self::assertSame(0, $this->reader->getInt($entity, 'zero'));
        self::assertSame(
            -1,
            $this->reader->getInt($entity, 'note', -1),
            '"abc" and 0 mean very different things to a quantity.'
        );
        self::assertSame(-1, $this->reader->getInt($entity, 'missing', -1));
    }

    public function testFloatsBehaveTheSameWay(): void
    {
        $entity = new DataObject(['price' => '19.99', 'weight' => 'heavy']);

        self::assertSame(19.99, $this->reader->getFloat($entity, 'price'));
        self::assertSame(1.0, $this->reader->getFloat($entity, 'weight', 1.0));
        self::assertSame(0.0, $this->reader->getFloat($entity, 'missing'));
    }

    /**
     * `(bool) '0'` is true, and so is the string `'false'`; yes/no semantics
     * are used instead.
     */
    public function testBooleansUseYesNoSemanticsAndNotPhpTruthiness(): void
    {
        $entity = new DataObject([
            'zero_string' => '0',
            'one_string' => '1',
            'false_word' => 'false',
            'true_word' => 'TRUE',
            'yes_word' => 'yes',
            'no_word' => 'No',
            'real_bool' => true,
        ]);

        self::assertFalse($this->reader->getBool($entity, 'zero_string'));
        self::assertTrue($this->reader->getBool($entity, 'one_string'));
        self::assertFalse($this->reader->getBool($entity, 'false_word'));
        self::assertTrue($this->reader->getBool($entity, 'true_word'));
        self::assertTrue($this->reader->getBool($entity, 'yes_word'));
        self::assertFalse($this->reader->getBool($entity, 'no_word'));
        self::assertTrue($this->reader->getBool($entity, 'real_bool'));
    }

    public function testAnUnsetBooleanTakesTheDefault(): void
    {
        $entity = new DataObject(['empty' => '', 'nothing' => null]);

        self::assertTrue($this->reader->getBool($entity, 'empty', true));
        self::assertTrue($this->reader->getBool($entity, 'nothing', true));
        self::assertTrue($this->reader->getBool($entity, 'missing', true));
        self::assertFalse($this->reader->getBool($entity, 'missing'));
    }

    public function testAListIsAListWhicheverFormItArrivesIn(): void
    {
        $stored = new DataObject(['fit' => '12,47']);
        $resolved = new DataObject(['fit' => [12, 47]]);
        $single = new DataObject(['fit' => '12']);

        self::assertSame(['12', '47'], $this->reader->getList($stored, 'fit'));
        self::assertSame(['12', '47'], $this->reader->getList($resolved, 'fit'));
        self::assertSame(['12'], $this->reader->getList($single, 'fit'));
        self::assertSame([], $this->reader->getList(new DataObject(), 'fit'));
    }

    public function testListEntriesAreTrimmedAndBlanksDropped(): void
    {
        $entity = new DataObject(['fit' => ' 12 ,, 47 ,']);

        self::assertSame(['12', '47'], $this->reader->getList($entity, 'fit'));
    }

    public function testASeparatorOfYourOwnIsHonoured(): void
    {
        $entity = new DataObject(['tags' => 'a|b|c']);

        self::assertSame(['a', 'b', 'c'], $this->reader->getList($entity, 'tags', '|'));
    }

    public function testLabelsAreDelegatedWithTheRawValue(): void
    {
        $entity = new DataObject(['color' => '247,248']);

        $this->optionLabels->expects(self::once())
            ->method('resolveValue')
            ->with('color', '247,248', 3)
            ->willReturn(['Ceil Blue', 'Wine']);

        self::assertSame(['Ceil Blue', 'Wine'], $this->reader->getLabels($entity, 'color', 3));
    }

    public function testAnEmptyAttributeCodeIsNotAskedFor(): void
    {
        $entity = new DataObject(['' => 'surprising but possible']);

        self::assertFalse($this->reader->has($entity, ''));
        self::assertSame('', $this->reader->getString($entity, ''));
        self::assertSame([], $this->reader->getList($entity, ''));
    }
}
