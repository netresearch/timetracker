<?php

declare(strict_types=1);

namespace Tests\Service\TypeSafety;

use App\Service\TypeSafety\ArrayTypeHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for ArrayTypeHelper.
 *
 * @internal
 */
#[CoversClass(ArrayTypeHelper::class)]
final class ArrayTypeHelperTest extends TestCase
{
    // ==================== getInt tests ====================

    public function testGetIntReturnsIntValue(): void
    {
        $array = ['value' => 42];

        self::assertSame(42, ArrayTypeHelper::getInt($array, 'value'));
    }

    public function testGetIntReturnsDefaultWhenKeyMissing(): void
    {
        $array = ['other' => 42];

        self::assertNull(ArrayTypeHelper::getInt($array, 'value'));
        self::assertSame(99, ArrayTypeHelper::getInt($array, 'value', 99));
    }

    public function testGetIntReturnsDefaultWhenValueIsNull(): void
    {
        $array = ['value' => null];

        self::assertNull(ArrayTypeHelper::getInt($array, 'value'));
        self::assertSame(99, ArrayTypeHelper::getInt($array, 'value', 99));
    }

    public function testGetIntConvertsNumericString(): void
    {
        $array = ['value' => '123'];

        self::assertSame(123, ArrayTypeHelper::getInt($array, 'value'));
    }

    public function testGetIntConvertsFloat(): void
    {
        $array = ['value' => 45.67];

        self::assertSame(45, ArrayTypeHelper::getInt($array, 'value'));
    }

    public function testGetIntReturnsDefaultForNonNumericValue(): void
    {
        $array = ['value' => 'not a number'];

        self::assertNull(ArrayTypeHelper::getInt($array, 'value'));
        self::assertSame(99, ArrayTypeHelper::getInt($array, 'value', 99));
    }

    public function testGetIntReturnsDefaultForArrayValue(): void
    {
        $array = ['value' => [1, 2, 3]];

        self::assertNull(ArrayTypeHelper::getInt($array, 'value'));
    }

    public function testGetIntReturnsDefaultForObjectValue(): void
    {
        $array = ['value' => new stdClass()];

        self::assertNull(ArrayTypeHelper::getInt($array, 'value'));
    }

    // ==================== getString tests ====================

    public function testGetStringReturnsStringValue(): void
    {
        $array = ['value' => 'hello'];

        self::assertSame('hello', ArrayTypeHelper::getString($array, 'value'));
    }

    public function testGetStringReturnsDefaultWhenKeyMissing(): void
    {
        $array = ['other' => 'hello'];

        self::assertNull(ArrayTypeHelper::getString($array, 'value'));
        self::assertSame('default', ArrayTypeHelper::getString($array, 'value', 'default'));
    }

    public function testGetStringReturnsDefaultWhenValueIsNull(): void
    {
        $array = ['value' => null];

        self::assertNull(ArrayTypeHelper::getString($array, 'value'));
        self::assertSame('default', ArrayTypeHelper::getString($array, 'value', 'default'));
    }

    public function testGetStringConvertsInt(): void
    {
        $array = ['value' => 42];

        self::assertSame('42', ArrayTypeHelper::getString($array, 'value'));
    }

    public function testGetStringConvertsFloat(): void
    {
        $array = ['value' => 3.14];

        self::assertSame('3.14', ArrayTypeHelper::getString($array, 'value'));
    }

    public function testGetStringConvertsBool(): void
    {
        $arrayTrue = ['value' => true];
        $arrayFalse = ['value' => false];

        self::assertSame('1', ArrayTypeHelper::getString($arrayTrue, 'value'));
        self::assertSame('', ArrayTypeHelper::getString($arrayFalse, 'value'));
    }

    public function testGetStringReturnsDefaultForArrayValue(): void
    {
        $array = ['value' => ['a', 'b']];

        self::assertNull(ArrayTypeHelper::getString($array, 'value'));
        self::assertSame('default', ArrayTypeHelper::getString($array, 'value', 'default'));
    }

    public function testGetStringReturnsDefaultForObjectValue(): void
    {
        $array = ['value' => new stdClass()];

        self::assertNull(ArrayTypeHelper::getString($array, 'value'));
    }

    // ==================== hasValue tests ====================

    public function testHasValueReturnsTrueForExistingNonNullValue(): void
    {
        $array = ['value' => 'hello'];

        self::assertTrue(ArrayTypeHelper::hasValue($array, 'value'));
    }

    public function testHasValueReturnsTrueForZeroValue(): void
    {
        $array = ['value' => 0];

        self::assertTrue(ArrayTypeHelper::hasValue($array, 'value'));
    }

    public function testHasValueReturnsTrueForEmptyStringValue(): void
    {
        $array = ['value' => ''];

        self::assertTrue(ArrayTypeHelper::hasValue($array, 'value'));
    }

    public function testHasValueReturnsTrueForFalseValue(): void
    {
        $array = ['value' => false];

        self::assertTrue(ArrayTypeHelper::hasValue($array, 'value'));
    }

    public function testHasValueReturnsFalseForNullValue(): void
    {
        $array = ['value' => null];

        self::assertFalse(ArrayTypeHelper::hasValue($array, 'value'));
    }

    public function testHasValueReturnsFalseForMissingKey(): void
    {
        $array = ['other' => 'hello'];

        self::assertFalse(ArrayTypeHelper::hasValue($array, 'value'));
    }

    public function testHasValueReturnsFalseForEmptyArray(): void
    {
        $array = [];

        self::assertFalse(ArrayTypeHelper::hasValue($array, 'value'));
    }

    // ==================== Edge cases ====================

    public function testGetIntWithZeroValue(): void
    {
        $array = ['value' => 0];

        self::assertSame(0, ArrayTypeHelper::getInt($array, 'value'));
        self::assertSame(0, ArrayTypeHelper::getInt($array, 'value', 99));
    }

    public function testGetStringWithEmptyString(): void
    {
        $array = ['value' => ''];

        self::assertSame('', ArrayTypeHelper::getString($array, 'value'));
        self::assertSame('', ArrayTypeHelper::getString($array, 'value', 'default'));
    }

    public function testGetIntWithNegativeNumber(): void
    {
        $array = ['value' => -42];

        self::assertSame(-42, ArrayTypeHelper::getInt($array, 'value'));
    }

    public function testGetIntWithNegativeNumericString(): void
    {
        $array = ['value' => '-123'];

        self::assertSame(-123, ArrayTypeHelper::getInt($array, 'value'));
    }
}
