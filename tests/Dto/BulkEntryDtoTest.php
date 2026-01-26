<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\BulkEntryDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for BulkEntryDto.
 *
 * @internal
 */
#[CoversClass(BulkEntryDto::class)]
final class BulkEntryDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaultValues(): void
    {
        $dto = new BulkEntryDto();

        self::assertSame(0, $dto->preset);
        self::assertSame('', $dto->startdate);
        self::assertSame('', $dto->enddate);
        self::assertSame('', $dto->starttime);
        self::assertSame('', $dto->endtime);
        self::assertSame(0, $dto->usecontract);
        self::assertSame(0, $dto->skipweekend);
        self::assertSame(0, $dto->skipholidays);
    }

    public function testConstructorWithCustomValues(): void
    {
        $dto = new BulkEntryDto(
            preset: 42,
            startdate: '2025-01-01',
            enddate: '2025-01-31',
            starttime: '09:00:00',
            endtime: '17:00:00',
            usecontract: 1,
            skipweekend: 1,
            skipholidays: 1,
        );

        self::assertSame(42, $dto->preset);
        self::assertSame('2025-01-01', $dto->startdate);
        self::assertSame('2025-01-31', $dto->enddate);
        self::assertSame('09:00:00', $dto->starttime);
        self::assertSame('17:00:00', $dto->endtime);
        self::assertSame(1, $dto->usecontract);
        self::assertSame(1, $dto->skipweekend);
        self::assertSame(1, $dto->skipholidays);
    }

    // ==================== Boolean helper method tests ====================

    public function testIsUseContractReturnsFalseForZero(): void
    {
        $dto = new BulkEntryDto(usecontract: 0);

        self::assertFalse($dto->isUseContract());
    }

    public function testIsUseContractReturnsTrueForPositive(): void
    {
        $dto = new BulkEntryDto(usecontract: 1);

        self::assertTrue($dto->isUseContract());
    }

    public function testIsSkipWeekendReturnsFalseForZero(): void
    {
        $dto = new BulkEntryDto(skipweekend: 0);

        self::assertFalse($dto->isSkipWeekend());
    }

    public function testIsSkipWeekendReturnsTrueForPositive(): void
    {
        $dto = new BulkEntryDto(skipweekend: 1);

        self::assertTrue($dto->isSkipWeekend());
    }

    public function testIsSkipHolidaysReturnsFalseForZero(): void
    {
        $dto = new BulkEntryDto(skipholidays: 0);

        self::assertFalse($dto->isSkipHolidays());
    }

    public function testIsSkipHolidaysReturnsTrueForPositive(): void
    {
        $dto = new BulkEntryDto(skipholidays: 1);

        self::assertTrue($dto->isSkipHolidays());
    }

    // ==================== validateTimeRange tests ====================

    public function testValidateTimeRangePassesWhenUsingContract(): void
    {
        $dto = new BulkEntryDto(
            starttime: '17:00:00',
            endtime: '09:00:00',  // Invalid: end before start
            usecontract: 1,  // But using contract, so validation skipped
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateTimeRange($context);
    }

    public function testValidateTimeRangePassesForValidRange(): void
    {
        $dto = new BulkEntryDto(
            starttime: '09:00:00',
            endtime: '17:00:00',  // Valid: end after start
            usecontract: 0,
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateTimeRange($context);
    }

    public function testValidateTimeRangeFailsForInvalidRange(): void
    {
        $dto = new BulkEntryDto(
            starttime: '17:00:00',
            endtime: '09:00:00',  // Invalid: end before start
            usecontract: 0,
        );

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $context = $this->createContextMock();
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('Die Aktivität muss mindestens eine Minute angedauert haben!')
            ->willReturn($violationBuilder);

        $dto->validateTimeRange($context);
    }

    public function testValidateTimeRangeFailsWhenStartEqualsEnd(): void
    {
        $dto = new BulkEntryDto(
            starttime: '12:00:00',
            endtime: '12:00:00',  // Invalid: same time
            usecontract: 0,
        );

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $context = $this->createContextMock();
        $context->expects(self::once())
            ->method('buildViolation')
            ->willReturn($violationBuilder);

        $dto->validateTimeRange($context);
    }

    public function testValidateTimeRangeSkipsWhenStartTimeEmpty(): void
    {
        $dto = new BulkEntryDto(
            starttime: '',
            endtime: '17:00:00',
            usecontract: 0,
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateTimeRange($context);
    }

    public function testValidateTimeRangeSkipsWhenEndTimeEmpty(): void
    {
        $dto = new BulkEntryDto(
            starttime: '09:00:00',
            endtime: '',
            usecontract: 0,
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateTimeRange($context);
    }

    // ==================== validateDateRange tests ====================

    public function testValidateDateRangePassesForValidRange(): void
    {
        $dto = new BulkEntryDto(
            startdate: '2025-01-01',
            enddate: '2025-01-31',
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateDateRange($context);
    }

    public function testValidateDateRangePassesWhenStartEqualsEnd(): void
    {
        $dto = new BulkEntryDto(
            startdate: '2025-01-15',
            enddate: '2025-01-15',  // Same day is valid
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateDateRange($context);
    }

    public function testValidateDateRangeFailsForInvalidRange(): void
    {
        $dto = new BulkEntryDto(
            startdate: '2025-01-31',
            enddate: '2025-01-01',  // Invalid: end before start
        );

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('atPath')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $context = $this->createContextMock();
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('Start date must be before or equal to end date')
            ->willReturn($violationBuilder);

        $dto->validateDateRange($context);
    }

    public function testValidateDateRangeSkipsWhenStartDateEmpty(): void
    {
        $dto = new BulkEntryDto(
            startdate: '',
            enddate: '2025-01-31',
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateDateRange($context);
    }

    public function testValidateDateRangeSkipsWhenEndDateEmpty(): void
    {
        $dto = new BulkEntryDto(
            startdate: '2025-01-01',
            enddate: '',
        );

        $context = $this->createContextMock();
        $context->expects(self::never())->method('buildViolation');

        $dto->validateDateRange($context);
    }

    // ==================== Helper methods ====================

    private function createContextMock(): ExecutionContextInterface&MockObject
    {
        return $this->createMock(ExecutionContextInterface::class);
    }
}
