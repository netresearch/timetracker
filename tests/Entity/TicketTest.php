<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class TicketTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $ticket = new Ticket(5, 'ABC-123', 'Test Ticket Name', 120, 'ABC-1');
        $ticket->setTicketId(99);

        self::assertSame('Test Ticket Name', $ticket->getName());
        self::assertSame('ABC-123', $ticket->getTicketNumber());
        self::assertSame(5, $ticket->getTicketSystemId());
        self::assertSame(120, $ticket->getEstimatedDuration());
        self::assertSame('ABC-1', $ticket->getParentTicketNumber());
        self::assertSame(99, $ticket->getTicketId());
    }
}
