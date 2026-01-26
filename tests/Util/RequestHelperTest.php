<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\RequestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for RequestHelper.
 *
 * @internal
 */
#[CoversClass(RequestHelper::class)]
final class RequestHelperTest extends TestCase
{
    // ==================== string tests ====================

    public function testStringReturnsValueWhenPresent(): void
    {
        $request = $this->createPostRequest(['name' => 'John']);

        self::assertSame('John', RequestHelper::string($request, 'name'));
    }

    public function testStringReturnsDefaultWhenMissing(): void
    {
        $request = $this->createPostRequest([]);

        self::assertSame('', RequestHelper::string($request, 'name'));
    }

    public function testStringReturnsCustomDefault(): void
    {
        $request = $this->createPostRequest([]);

        self::assertSame('default', RequestHelper::string($request, 'name', 'default'));
    }

    public function testStringConvertsNumericToString(): void
    {
        $request = $this->createPostRequest(['count' => 42]);

        self::assertSame('42', RequestHelper::string($request, 'count'));
    }

    // ==================== nullableString tests ====================

    public function testNullableStringReturnsValueWhenPresent(): void
    {
        $request = $this->createPostRequest(['email' => 'test@example.com']);

        self::assertSame('test@example.com', RequestHelper::nullableString($request, 'email'));
    }

    public function testNullableStringReturnsNullWhenMissing(): void
    {
        $request = $this->createPostRequest([]);

        self::assertNull(RequestHelper::nullableString($request, 'email'));
    }

    public function testNullableStringReturnsNullForEmptyString(): void
    {
        $request = $this->createPostRequest(['email' => '']);

        self::assertNull(RequestHelper::nullableString($request, 'email'));
    }

    public function testNullableStringReturnsNullForWhitespaceOnly(): void
    {
        $request = $this->createPostRequest(['email' => '   ']);

        self::assertNull(RequestHelper::nullableString($request, 'email'));
    }

    public function testNullableStringTrimsWhitespace(): void
    {
        $request = $this->createPostRequest(['email' => '  test@example.com  ']);

        self::assertSame('test@example.com', RequestHelper::nullableString($request, 'email'));
    }

    // ==================== bool tests ====================

    public function testBoolReturnsTrueForBooleanTrue(): void
    {
        $request = $this->createPostRequest(['active' => true]);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsFalseForBooleanFalse(): void
    {
        $request = $this->createPostRequest(['active' => false]);

        self::assertFalse(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsTrueForStringOne(): void
    {
        $request = $this->createPostRequest(['active' => '1']);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsTrueForStringTrue(): void
    {
        $request = $this->createPostRequest(['active' => 'true']);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsTrueForStringOn(): void
    {
        $request = $this->createPostRequest(['active' => 'on']);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsTrueForStringYes(): void
    {
        $request = $this->createPostRequest(['active' => 'yes']);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsFalseForStringZero(): void
    {
        $request = $this->createPostRequest(['active' => '0']);

        self::assertFalse(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsFalseForStringFalse(): void
    {
        $request = $this->createPostRequest(['active' => 'false']);

        self::assertFalse(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsDefaultWhenMissing(): void
    {
        $request = $this->createPostRequest([]);

        self::assertFalse(RequestHelper::bool($request, 'active'));
    }

    public function testBoolReturnsCustomDefault(): void
    {
        $request = $this->createPostRequest([]);

        self::assertTrue(RequestHelper::bool($request, 'active', true));
    }

    public function testBoolIsCaseInsensitive(): void
    {
        $request = $this->createPostRequest(['active' => 'TRUE']);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    public function testBoolTrimsWhitespace(): void
    {
        $request = $this->createPostRequest(['active' => '  yes  ']);

        self::assertTrue(RequestHelper::bool($request, 'active'));
    }

    // ==================== int tests ====================

    public function testIntReturnsValueWhenPresent(): void
    {
        $request = $this->createPostRequest(['count' => '42']);

        self::assertSame(42, RequestHelper::int($request, 'count'));
    }

    public function testIntReturnsNullWhenMissing(): void
    {
        $request = $this->createPostRequest([]);

        self::assertNull(RequestHelper::int($request, 'count'));
    }

    public function testIntReturnsNullForEmptyString(): void
    {
        $request = $this->createPostRequest(['count' => '']);

        self::assertNull(RequestHelper::int($request, 'count'));
    }

    public function testIntReturnsCustomDefault(): void
    {
        $request = $this->createPostRequest([]);

        self::assertSame(10, RequestHelper::int($request, 'count', 10));
    }

    public function testIntReturnsZeroForNonNumericString(): void
    {
        $request = $this->createPostRequest(['count' => 'abc']);

        self::assertSame(0, RequestHelper::int($request, 'count'));
    }

    public function testIntConvertsFloatToInt(): void
    {
        $request = $this->createPostRequest(['count' => '3.14']);

        self::assertSame(3, RequestHelper::int($request, 'count'));
    }

    // ==================== upperString tests ====================

    public function testUpperStringReturnsUppercasedValue(): void
    {
        $request = $this->createPostRequest(['type' => 'admin']);

        self::assertSame('ADMIN', RequestHelper::upperString($request, 'type'));
    }

    public function testUpperStringReturnsDefaultWhenMissing(): void
    {
        $request = $this->createPostRequest([]);

        self::assertSame('', RequestHelper::upperString($request, 'type'));
    }

    public function testUpperStringReturnsCustomDefaultUppercased(): void
    {
        $request = $this->createPostRequest([]);

        self::assertSame('USER', RequestHelper::upperString($request, 'type', 'user'));
    }

    public function testUpperStringTrimsWhitespace(): void
    {
        $request = $this->createPostRequest(['type' => '  admin  ']);

        self::assertSame('ADMIN', RequestHelper::upperString($request, 'type'));
    }

    // ==================== Helper methods ====================

    /**
     * @param array<string, mixed> $data
     */
    private function createPostRequest(array $data): Request
    {
        $request = Request::create('/', 'POST');
        $request->request->replace($data);

        return $request;
    }
}
