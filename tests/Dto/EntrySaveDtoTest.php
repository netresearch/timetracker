<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\EntrySaveDto;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tests for EntrySaveDto.
 *
 * @internal
 */
#[CoversClass(EntrySaveDto::class)]
final class EntrySaveDtoTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get('validator');
    }

    protected function tearDown(): void
    {
        // Clean up exception handlers that might have been set during validation
        while (null !== set_exception_handler(null)) {
            // Loop to remove all custom exception handlers
        }

        parent::tearDown();
    }

    // ==================== Constructor tests ====================

    public function testConstructorWithDefaults(): void
    {
        $dto = new EntrySaveDto();

        self::assertNull($dto->id);
        self::assertSame('', $dto->date);
        self::assertSame('00:00:00', $dto->start);
        self::assertSame('00:00:00', $dto->end);
        self::assertSame('', $dto->ticket);
        self::assertSame('', $dto->description);
        self::assertNull($dto->project_id);
        self::assertNull($dto->customer_id);
        self::assertNull($dto->activity_id);
        self::assertNull($dto->project);
        self::assertNull($dto->customer);
        self::assertNull($dto->activity);
        self::assertSame('', $dto->extTicket);
    }

    // ==================== getCustomerId tests ====================

    public function testGetCustomerIdReturnsCustomerIdWhenSet(): void
    {
        $dto = new EntrySaveDto(customer_id: 42);

        self::assertSame(42, $dto->getCustomerId());
    }

    public function testGetCustomerIdFallsBackToLegacyCustomer(): void
    {
        $dto = new EntrySaveDto(customer: 99);

        self::assertSame(99, $dto->getCustomerId());
    }

    public function testGetCustomerIdPrefersCustomerIdOverLegacy(): void
    {
        $dto = new EntrySaveDto(customer_id: 10, customer: 20);

        self::assertSame(10, $dto->getCustomerId());
    }

    // ==================== getProjectId tests ====================

    public function testGetProjectIdReturnsProjectIdWhenSet(): void
    {
        $dto = new EntrySaveDto(project_id: 42);

        self::assertSame(42, $dto->getProjectId());
    }

    public function testGetProjectIdFallsBackToLegacyProject(): void
    {
        $dto = new EntrySaveDto(project: 99);

        self::assertSame(99, $dto->getProjectId());
    }

    public function testGetProjectIdPrefersProjectIdOverLegacy(): void
    {
        $dto = new EntrySaveDto(project_id: 10, project: 20);

        self::assertSame(10, $dto->getProjectId());
    }

    // ==================== getActivityId tests ====================

    public function testGetActivityIdReturnsActivityIdWhenSet(): void
    {
        $dto = new EntrySaveDto(activity_id: 42);

        self::assertSame(42, $dto->getActivityId());
    }

    public function testGetActivityIdFallsBackToLegacyActivity(): void
    {
        $dto = new EntrySaveDto(activity: 99);

        self::assertSame(99, $dto->getActivityId());
    }

    public function testGetActivityIdPrefersActivityIdOverLegacy(): void
    {
        $dto = new EntrySaveDto(activity_id: 10, activity: 20);

        self::assertSame(10, $dto->getActivityId());
    }

    // ==================== getDateAsDateTime tests ====================

    public function testGetDateAsDateTimeReturnsNullForEmptyString(): void
    {
        $dto = new EntrySaveDto(date: '');

        self::assertNull($dto->getDateAsDateTime());
    }

    public function testGetDateAsDateTimeReturnsNullForZero(): void
    {
        $dto = new EntrySaveDto(date: '0');

        self::assertNull($dto->getDateAsDateTime());
    }

    public function testGetDateAsDateTimeSupportsIso8601Format(): void
    {
        $dto = new EntrySaveDto(date: '2024-01-15T00:00:00');

        $result = $dto->getDateAsDateTime();

        self::assertNotNull($result);
        self::assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    public function testGetDateAsDateTimeSupportsYmdFormat(): void
    {
        $dto = new EntrySaveDto(date: '2024-01-15');

        $result = $dto->getDateAsDateTime();

        self::assertNotNull($result);
        self::assertSame('2024-01-15', $result->format('Y-m-d'));
    }

    public function testGetDateAsDateTimeReturnsNullForInvalidDate(): void
    {
        $dto = new EntrySaveDto(date: 'not-a-date');

        self::assertNull($dto->getDateAsDateTime());
    }

    // ==================== getStartAsDateTime tests ====================

    public function testGetStartAsDateTimeReturnsNullForEmptyString(): void
    {
        $dto = new EntrySaveDto(start: '');

        self::assertNull($dto->getStartAsDateTime());
    }

    public function testGetStartAsDateTimeReturnsNullForZero(): void
    {
        $dto = new EntrySaveDto(start: '0');

        self::assertNull($dto->getStartAsDateTime());
    }

    public function testGetStartAsDateTimeSupportsIso8601Format(): void
    {
        $dto = new EntrySaveDto(start: '2024-01-15T09:30:00');

        $result = $dto->getStartAsDateTime();

        self::assertNotNull($result);
        self::assertSame('09:30:00', $result->format('H:i:s'));
    }

    public function testGetStartAsDateTimeSupportsHisFormat(): void
    {
        $dto = new EntrySaveDto(start: '09:30:00');

        $result = $dto->getStartAsDateTime();

        self::assertNotNull($result);
        self::assertSame('09:30:00', $result->format('H:i:s'));
    }

    public function testGetStartAsDateTimeSupportsHiFormat(): void
    {
        $dto = new EntrySaveDto(start: '09:30');

        $result = $dto->getStartAsDateTime();

        self::assertNotNull($result);
        self::assertSame('09:30', $result->format('H:i'));
    }

    public function testGetStartAsDateTimeReturnsNullForInvalidTime(): void
    {
        $dto = new EntrySaveDto(start: 'not-a-time');

        self::assertNull($dto->getStartAsDateTime());
    }

    // ==================== getEndAsDateTime tests ====================

    public function testGetEndAsDateTimeReturnsNullForEmptyString(): void
    {
        $dto = new EntrySaveDto(end: '');

        self::assertNull($dto->getEndAsDateTime());
    }

    public function testGetEndAsDateTimeReturnsNullForZero(): void
    {
        $dto = new EntrySaveDto(end: '0');

        self::assertNull($dto->getEndAsDateTime());
    }

    public function testGetEndAsDateTimeSupportsIso8601Format(): void
    {
        $dto = new EntrySaveDto(end: '2024-01-15T17:00:00');

        $result = $dto->getEndAsDateTime();

        self::assertNotNull($result);
        self::assertSame('17:00:00', $result->format('H:i:s'));
    }

    public function testGetEndAsDateTimeSupportsHisFormat(): void
    {
        $dto = new EntrySaveDto(end: '17:00:00');

        $result = $dto->getEndAsDateTime();

        self::assertNotNull($result);
        self::assertSame('17:00:00', $result->format('H:i:s'));
    }

    public function testGetEndAsDateTimeSupportsHiFormat(): void
    {
        $dto = new EntrySaveDto(end: '17:00');

        $result = $dto->getEndAsDateTime();

        self::assertNotNull($result);
        self::assertSame('17:00', $result->format('H:i'));
    }

    public function testGetEndAsDateTimeReturnsNullForInvalidTime(): void
    {
        $dto = new EntrySaveDto(end: 'not-a-time');

        self::assertNull($dto->getEndAsDateTime());
    }

    // ==================== Validation tests ====================

    public function testValidDto(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '09:00:00',
            end: '17:00:00',
            ticket: 'JIRA-123',
            description: 'Working on feature',
            project_id: 1,
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testInvalidDate(): void
    {
        $dto = new EntrySaveDto(
            date: 'invalid-date',
            start: '09:00:00',
            end: '17:00:00',
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Invalid date format', (string) $violations);
    }

    public function testInvalidTicketFormat(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '09:00:00',
            end: '17:00:00',
            ticket: 'ticket with spaces!@#',
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Invalid ticket format', (string) $violations);
    }

    public function testTicketTooLong(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '09:00:00',
            end: '17:00:00',
            ticket: str_repeat('A', 51),
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Ticket cannot be longer than 50 characters', (string) $violations);
    }

    public function testDescriptionTooLong(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '09:00:00',
            end: '17:00:00',
            description: str_repeat('a', 1001),
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Description cannot be longer than 1000 characters', (string) $violations);
    }

    public function testInvalidTimeRange(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '17:00:00',
            end: '09:00:00', // End before start
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Start time must be before end time', (string) $violations);
    }

    public function testNegativeProjectId(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '09:00:00',
            end: '17:00:00',
            project_id: -1,
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Project ID must be positive', (string) $violations);
    }
}
