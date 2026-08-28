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

class AttributeValueReaderTest extends TestCase
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

        $this->assertSame('', $this->reader->getString($entity, 'url'));
        $this->assertSame('', $this->reader->getString($entity, 'never_set'));
        $this->assertSame('placeholder', $this->reader->getString($entity, 'url', 'placeholder'));
    }

    public function testAStringComesBackUnchanged(): void
    {
        $entity = new DataObject(['name' => 'Ceil Blue Scrub Top']);

        $this->assertSame('Ceil Blue Scrub Top', $this->reader->getString($entity, 'name'));
    }

    public function testNumbersAreStringifiedRatherThanRefused(): void
    {
        $entity = new DataObject(['position' => 12, 'price' => 19.99]);

        $this->assertSame('12', $this->reader->getString($entity, 'position'));
        $this->assertSame('19.99', $this->reader->getString($entity, 'price'));
    }

    /**
     * Magento returns Phrases wherever a label or a message is involved, and a
     * Phrase is Stringable rather than a string.
     */
    public function testAPhraseIsReadAsItsText(): void
    {
        $entity = new DataObject(['label' => new Phrase('Out of stock')]);

        $this->assertSame('Out of stock', $this->reader->getString($entity, 'label'));
    }

    /**
     * Casting a multiselect to a string is how a feed column comes to contain
     * the literal word "Array" — which is a support ticket, not an error.
     */
    public function testAnArrayIsNotSilentlyStringified(): void
    {
        $entity = new DataObject(['fit' => ['Petite', 'Tall']]);

        $this->assertSame('', $this->reader->getString($entity, 'fit'));
        $this->assertSame('n/a', $this->reader->getString($entity, 'fit', 'n/a'));
        $this->assertStringNotContainsString('Array', $this->reader->getString($entity, 'fit'));
    }

    /**
     * A product loaded with a narrow attribute set answers null for everything
     * it did not select.
     */
    public function testAbsentIsTellableFromEmpty(): void
    {
        $entity = new DataObject(['description' => '']);

        $this->assertTrue($this->reader->has($entity, 'description'));
        $this->assertFalse($this->reader->has($entity, 'short_description'));
        $this->assertNull($this->reader->getStringOrNull($entity, 'description'));
        $this->assertNull($this->reader->getStringOrNull($entity, 'short_description'));
    }

    public function testIntegersFallBackRatherThanCollapsingToZero(): void
    {
        $entity = new DataObject(['qty' => '14', 'note' => 'not a number', 'zero' => '0']);

        $this->assertSame(14, $this->reader->getInt($entity, 'qty'));
        $this->assertSame(0, $this->reader->getInt($entity, 'zero'));
        $this->assertSame(
            -1,
            $this->reader->getInt($entity, 'note', -1),
            '"abc" and 0 mean very different things to a quantity.'
        );
        $this->assertSame(-1, $this->reader->getInt($entity, 'missing', -1));
    }

    public function testFloatsBehaveTheSameWay(): void
    {
        $entity = new DataObject(['price' => '19.99', 'weight' => 'heavy']);

        $this->assertSame(19.99, $this->reader->getFloat($entity, 'price'));
        $this->assertSame(1.0, $this->reader->getFloat($entity, 'weight', 1.0));
        $this->assertSame(0.0, $this->reader->getFloat($entity, 'missing'));
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

        $this->assertFalse($this->reader->getBool($entity, 'zero_string'));
        $this->assertTrue($this->reader->getBool($entity, 'one_string'));
        $this->assertFalse($this->reader->getBool($entity, 'false_word'));
        $this->assertTrue($this->reader->getBool($entity, 'true_word'));
        $this->assertTrue($this->reader->getBool($entity, 'yes_word'));
        $this->assertFalse($this->reader->getBool($entity, 'no_word'));
        $this->assertTrue($this->reader->getBool($entity, 'real_bool'));
    }

    public function testAnUnsetBooleanTakesTheDefault(): void
    {
        $entity = new DataObject(['empty' => '', 'nothing' => null]);

        $this->assertTrue($this->reader->getBool($entity, 'empty', true));
        $this->assertTrue($this->reader->getBool($entity, 'nothing', true));
        $this->assertTrue($this->reader->getBool($entity, 'missing', true));
        $this->assertFalse($this->reader->getBool($entity, 'missing'));
    }

    public function testAListIsAListWhicheverFormItArrivesIn(): void
    {
        $stored = new DataObject(['fit' => '12,47']);
        $resolved = new DataObject(['fit' => [12, 47]]);
        $single = new DataObject(['fit' => '12']);

        $this->assertSame(['12', '47'], $this->reader->getList($stored, 'fit'));
        $this->assertSame(['12', '47'], $this->reader->getList($resolved, 'fit'));
        $this->assertSame(['12'], $this->reader->getList($single, 'fit'));
        $this->assertSame([], $this->reader->getList(new DataObject(), 'fit'));
    }

    public function testListEntriesAreTrimmedAndBlanksDropped(): void
    {
        $entity = new DataObject(['fit' => ' 12 ,, 47 ,']);

        $this->assertSame(['12', '47'], $this->reader->getList($entity, 'fit'));
    }

    public function testASeparatorOfYourOwnIsHonoured(): void
    {
        $entity = new DataObject(['tags' => 'a|b|c']);

        $this->assertSame(['a', 'b', 'c'], $this->reader->getList($entity, 'tags', '|'));
    }

    public function testLabelsAreDelegatedWithTheRawValue(): void
    {
        $entity = new DataObject(['color' => '247,248']);

        $this->optionLabels->expects($this->once())
            ->method('resolveValue')
            ->with('color', '247,248', 3)
            ->willReturn(['Ceil Blue', 'Wine']);

        $this->assertSame(['Ceil Blue', 'Wine'], $this->reader->getLabels($entity, 'color', 3));
    }

    public function testAnEmptyAttributeCodeIsNotAskedFor(): void
    {
        $entity = new DataObject(['' => 'surprising but possible']);

        $this->assertFalse($this->reader->has($entity, ''));
        $this->assertSame('', $this->reader->getString($entity, ''));
        $this->assertSame([], $this->reader->getList($entity, ''));
    }
}
