<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\DatabaseResultDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DatabaseResultDto.
 *
 * @internal
 */
#[CoversClass(DatabaseResultDto::class)]
final class DatabaseResultDtoTest extends TestCase
{
    // ==================== transformEntryRow tests ====================

    public function testTransformEntryRowWithFullData(): void
    {
        $row = [
            'id' => 1,
            'date' => '2025-01-15',
            'start' => '09:00:00',
            'end' => '17:00:00',
            'user' => 42,
            'customer' => 10,
            'project' => 20,
            'activity' => 5,
            'description' => 'Test entry',
            'ticket' => 'JIRA-123',
            'class' => 1,
            'duration' => 480,
            'extTicket' => 'EXT-456',
            'extTicketUrl' => 'https://example.com/EXT-456',
        ];

        $result = DatabaseResultDto::transformEntryRow($row);

        self::assertSame(1, $result['id']);
        self::assertSame('2025-01-15', $result['date']);
        self::assertSame('09:00:00', $result['start']);
        self::assertSame('17:00:00', $result['end']);
        self::assertSame(42, $result['user']);
        self::assertSame(10, $result['customer']);
        self::assertSame(20, $result['project']);
        self::assertSame(5, $result['activity']);
        self::assertSame('Test entry', $result['description']);
        self::assertSame('JIRA-123', $result['ticket']);
        self::assertSame(1, $result['class']);
        self::assertSame(480, $result['duration']);
        self::assertSame('EXT-456', $result['extTicket']);
        self::assertSame('https://example.com/EXT-456', $result['extTicketUrl']);
    }

    public function testTransformEntryRowWithEmptyArray(): void
    {
        $result = DatabaseResultDto::transformEntryRow([]);

        self::assertSame(0, $result['id']);
        self::assertSame('', $result['date']);
        self::assertSame('', $result['start']);
        self::assertSame('', $result['end']);
        self::assertSame(0, $result['user']);
        self::assertSame(0, $result['customer']);
        self::assertSame(0, $result['project']);
        self::assertSame(0, $result['activity']);
        self::assertSame('', $result['description']);
        self::assertSame('', $result['ticket']);
        self::assertSame(0, $result['class']);
        self::assertSame(0, $result['duration']);
        self::assertSame('', $result['extTicket']);
        self::assertSame('', $result['extTicketUrl']);
    }

    public function testTransformEntryRowWithStringNumericValues(): void
    {
        $row = [
            'id' => '42',
            'duration' => '120',
        ];

        $result = DatabaseResultDto::transformEntryRow($row);

        self::assertSame(42, $result['id']);
        self::assertSame(120, $result['duration']);
    }

    public function testTransformEntryRowWithNonNumericValues(): void
    {
        $row = [
            'id' => 'not-a-number',
            'duration' => [],
        ];

        $result = DatabaseResultDto::transformEntryRow($row);

        self::assertSame(0, $result['id']);
        self::assertSame(0, $result['duration']);
    }

    public function testTransformEntryRowWithNumericStrings(): void
    {
        $row = [
            'description' => 12345, // Numeric will be converted to string
        ];

        $result = DatabaseResultDto::transformEntryRow($row);

        self::assertSame('12345', $result['description']);
    }

    public function testTransformEntryRowWithNonStringDescription(): void
    {
        $row = [
            'description' => ['array', 'value'], // Non-string, non-numeric
        ];

        $result = DatabaseResultDto::transformEntryRow($row);

        self::assertSame('', $result['description']);
    }

    // ==================== transformScopeRow tests ====================

    public function testTransformScopeRowWithFullData(): void
    {
        $row = [
            'name' => 'Test Project',
            'entries' => 10,
            'total' => 500,
            'own' => 300,
            'estimation' => 600,
        ];

        $result = DatabaseResultDto::transformScopeRow($row, 'project');

        self::assertSame('project', $result['scope']);
        self::assertSame('Test Project', $result['name']);
        self::assertSame(10, $result['entries']);
        self::assertSame(500, $result['total']);
        self::assertSame(300, $result['own']);
        self::assertSame(600, $result['estimation']);
    }

    public function testTransformScopeRowWithEmptyArray(): void
    {
        $result = DatabaseResultDto::transformScopeRow([], 'customer');

        self::assertSame('customer', $result['scope']);
        self::assertSame('', $result['name']);
        self::assertSame(0, $result['entries']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['own']);
        self::assertSame(0, $result['estimation']);
    }

    public function testTransformScopeRowWithStringNumericValues(): void
    {
        $row = [
            'entries' => '25',
            'total' => '1000',
        ];

        $result = DatabaseResultDto::transformScopeRow($row, 'activity');

        self::assertSame(25, $result['entries']);
        self::assertSame(1000, $result['total']);
    }

    // ==================== safeDateTime tests ====================

    public function testSafeDateTimeWithValidDateTimeString(): void
    {
        $result = DatabaseResultDto::safeDateTime('2025-01-15 10:30:00');

        self::assertSame('2025-01-15 10:30:00', $result);
    }

    public function testSafeDateTimeWithValidDateOnlyString(): void
    {
        $result = DatabaseResultDto::safeDateTime('2025-01-15');

        self::assertSame('2025-01-15', $result);
    }

    public function testSafeDateTimeWithEmptyString(): void
    {
        $result = DatabaseResultDto::safeDateTime('');

        self::assertSame('', $result);
    }

    public function testSafeDateTimeWithZeroString(): void
    {
        $result = DatabaseResultDto::safeDateTime('0');

        self::assertSame('', $result);
    }

    public function testSafeDateTimeWithNonStringValue(): void
    {
        $result = DatabaseResultDto::safeDateTime(12345);

        self::assertSame('', $result);
    }

    public function testSafeDateTimeWithNullValue(): void
    {
        $result = DatabaseResultDto::safeDateTime(null);

        self::assertSame('', $result);
    }

    public function testSafeDateTimeWithInvalidDateString(): void
    {
        $result = DatabaseResultDto::safeDateTime('not-a-date');

        self::assertSame('', $result);
    }

    public function testSafeDateTimeWithCustomDefault(): void
    {
        $result = DatabaseResultDto::safeDateTime(null, '1970-01-01');

        self::assertSame('1970-01-01', $result);
    }

    public function testSafeDateTimeWithInvalidDateReturnsCustomDefault(): void
    {
        $result = DatabaseResultDto::safeDateTime('invalid', 'N/A');

        self::assertSame('N/A', $result);
    }

    public function testSafeDateTimeWithArrayValue(): void
    {
        $result = DatabaseResultDto::safeDateTime(['not', 'a', 'string']);

        self::assertSame('', $result);
    }
}
