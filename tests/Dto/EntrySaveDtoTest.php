<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\EntrySaveDto;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 *
 * @coversNothing
 */
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
        restore_exception_handler();
        restore_error_handler();
        parent::tearDown();
    }

    public function testValidDto(): void
    {
        $dto = new EntrySaveDto(
            date: '2024-01-15',
            start: '09:00:00',
            end: '17:00:00',
            ticket: 'JIRA-123',
            description: 'Working on feature',
            project_id: 1
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testInvalidDate(): void
    {
        $dto = new EntrySaveDto(
            date: 'invalid-date',
            start: '09:00:00',
            end: '17:00:00'
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
            ticket: 'ticket with spaces!@#'
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
            ticket: str_repeat('A', 51)
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
            description: str_repeat('a', 1001)
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
            end: '09:00:00' // End before start
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
            project_id: -1
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertStringContainsString('Project ID must be positive', (string) $violations);
    }
}
