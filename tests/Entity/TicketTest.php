<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Ticket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Ticket entity.
 *
 * @internal
 */
#[CoversClass(Ticket::class)]
final class TicketTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorSetsAllRequiredFields(): void
    {
        $ticket = new Ticket(5, 'ABC-123', 'Test Ticket Name');

        self::assertSame(5, $ticket->getTicketSystemId());
        self::assertSame('ABC-123', $ticket->getTicketNumber());
        self::assertSame('Test Ticket Name', $ticket->getName());
    }

    public function testConstructorSetsDefaultValues(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        self::assertSame(0, $ticket->getEstimatedDuration());
        self::assertSame('', $ticket->getParentTicketNumber());
    }

    public function testConstructorWithAllParameters(): void
    {
        $ticket = new Ticket(10, 'PROJ-999', 'Full Ticket', 240, 'PROJ-1');

        self::assertSame(10, $ticket->getTicketSystemId());
        self::assertSame('PROJ-999', $ticket->getTicketNumber());
        self::assertSame('Full Ticket', $ticket->getName());
        self::assertSame(240, $ticket->getEstimatedDuration());
        self::assertSame('PROJ-1', $ticket->getParentTicketNumber());
    }

    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        self::assertNull($ticket->getId());
    }

    // ==================== TicketId tests ====================

    public function testTicketIdIsNullByDefault(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        self::assertNull($ticket->getTicketId());
    }

    public function testSetTicketIdReturnsFluentInterface(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        $result = $ticket->setTicketId(42);

        self::assertSame($ticket, $result);
        self::assertSame(42, $ticket->getTicketId());
    }

    // ==================== Name tests ====================

    public function testSetNameReturnsFluentInterface(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Original Name');

        $result = $ticket->setName('New Name');

        self::assertSame($ticket, $result);
        self::assertSame('New Name', $ticket->getName());
    }

    public function testSetNameAllowsEmptyString(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        $ticket->setName('');

        self::assertSame('', $ticket->getName());
    }

    // ==================== TicketNumber tests ====================

    public function testSetTicketNumberReturnsFluentInterface(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        $result = $ticket->setTicketNumber('PROJ-999');

        self::assertSame($ticket, $result);
        self::assertSame('PROJ-999', $ticket->getTicketNumber());
    }

    // ==================== TicketSystemId tests ====================

    public function testSetTicketSystemIdReturnsFluentInterface(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        $result = $ticket->setTicketSystemId(99);

        self::assertSame($ticket, $result);
        self::assertSame(99, $ticket->getTicketSystemId());
    }

    // ==================== EstimatedDuration tests ====================

    public function testSetEstimatedDurationReturnsFluentInterface(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        $result = $ticket->setEstimatedDuration(180);

        self::assertSame($ticket, $result);
        self::assertSame(180, $ticket->getEstimatedDuration());
    }

    public function testSetEstimatedDurationToZero(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test', 120);

        $ticket->setEstimatedDuration(0);

        self::assertSame(0, $ticket->getEstimatedDuration());
    }

    // ==================== ParentTicketNumber tests ====================

    public function testSetParentTicketNumberReturnsFluentInterface(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test');

        $result = $ticket->setParentTicketNumber('PROJ-100');

        self::assertSame($ticket, $result);
        self::assertSame('PROJ-100', $ticket->getParentTicketNumber());
    }

    public function testSetParentTicketNumberToEmpty(): void
    {
        $ticket = new Ticket(1, 'PROJ-1', 'Test', 0, 'PROJ-100');

        $ticket->setParentTicketNumber('');

        self::assertSame('', $ticket->getParentTicketNumber());
    }
}
