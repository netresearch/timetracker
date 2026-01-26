<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\ExportQueryDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for ExportQueryDto.
 *
 * @internal
 */
#[CoversClass(ExportQueryDto::class)]
final class ExportQueryDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaultValues(): void
    {
        $dto = new ExportQueryDto();

        self::assertSame(0, $dto->userid);
        self::assertSame(0, $dto->year);
        self::assertSame(0, $dto->month);
        self::assertNull($dto->project);
        self::assertNull($dto->customer);
        self::assertFalse($dto->billable);
        self::assertFalse($dto->tickettitles);
    }

    public function testConstructorWithCustomValues(): void
    {
        $dto = new ExportQueryDto(
            userid: 42,
            year: 2025,
            month: 1,
            project: 10,
            customer: 5,
            billable: true,
            tickettitles: true,
        );

        self::assertSame(42, $dto->userid);
        self::assertSame(2025, $dto->year);
        self::assertSame(1, $dto->month);
        self::assertSame(10, $dto->project);
        self::assertSame(5, $dto->customer);
        self::assertTrue($dto->billable);
        self::assertTrue($dto->tickettitles);
    }

    // ==================== fromRequest tests ====================

    public function testFromRequestWithAllParameters(): void
    {
        $request = Request::create('/export', 'GET', [
            'userid' => '42',
            'year' => '2025',
            'month' => '1',
            'project' => '10',
            'customer' => '5',
            'billable' => '1',
            'tickettitles' => 'true',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(42, $dto->userid);
        self::assertSame(2025, $dto->year);
        self::assertSame(1, $dto->month);
        self::assertSame(10, $dto->project);
        self::assertSame(5, $dto->customer);
        self::assertTrue($dto->billable);
        self::assertTrue($dto->tickettitles);
    }

    public function testFromRequestWithMinimalParameters(): void
    {
        $request = Request::create('/export', 'GET', []);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(0, $dto->userid);
        self::assertSame(0, $dto->year);
        self::assertSame(0, $dto->month);
        self::assertNull($dto->project);
        self::assertNull($dto->customer);
        self::assertFalse($dto->billable);
        self::assertFalse($dto->tickettitles);
    }

    // ==================== toNullableId conversion tests ====================

    public function testFromRequestProjectNullForEmptyString(): void
    {
        $request = Request::create('/export', 'GET', [
            'project' => '',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertNull($dto->project);
    }

    public function testFromRequestProjectNullForZero(): void
    {
        $request = Request::create('/export', 'GET', [
            'project' => '0',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertNull($dto->project);
    }

    public function testFromRequestCustomerNullForNonNumeric(): void
    {
        $request = Request::create('/export', 'GET', [
            'customer' => 'not-a-number',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertNull($dto->customer);
    }

    public function testFromRequestCustomerValueForValidNumber(): void
    {
        $request = Request::create('/export', 'GET', [
            'customer' => '123',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(123, $dto->customer);
    }

    // ==================== toInt conversion tests ====================

    public function testFromRequestUserIdZeroForNull(): void
    {
        $request = Request::create('/export', 'GET', []);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(0, $dto->userid);
    }

    public function testFromRequestUserIdZeroForEmptyString(): void
    {
        $request = Request::create('/export', 'GET', [
            'userid' => '',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(0, $dto->userid);
    }

    public function testFromRequestUserIdZeroForNonNumeric(): void
    {
        $request = Request::create('/export', 'GET', [
            'userid' => 'invalid',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(0, $dto->userid);
    }

    // ==================== toBool conversion tests ====================

    public function testFromRequestBillableTrueForOne(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => '1',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertTrue($dto->billable);
    }

    public function testFromRequestBillableTrueForTrue(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => 'true',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertTrue($dto->billable);
    }

    public function testFromRequestBillableTrueForOn(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => 'on',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertTrue($dto->billable);
    }

    public function testFromRequestBillableTrueForYes(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => 'yes',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertTrue($dto->billable);
    }

    public function testFromRequestBillableFalseForZero(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => '0',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertFalse($dto->billable);
    }

    public function testFromRequestBillableFalseForFalse(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => 'false',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertFalse($dto->billable);
    }

    public function testFromRequestBillableFalseForNo(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => 'no',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertFalse($dto->billable);
    }

    public function testFromRequestBillableTrueWithMixedCase(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => 'TRUE',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertTrue($dto->billable);
    }

    public function testFromRequestBillableTrueWithWhitespace(): void
    {
        $request = Request::create('/export', 'GET', [
            'billable' => '  yes  ',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertTrue($dto->billable);
    }
}
