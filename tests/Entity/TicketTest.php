<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Ticket;
use PHPUnit\Framework\TestCase;

class TicketTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $ticket = new Ticket();
        $ticket->setName('ABC-123');
        $ticket->setTicketNumber('ABC-123');
        $ticket->setTicketSystemId(5);
        $ticket->setEstimatedDuration(120);
        $ticket->setParentTicketNumber('ABC-1');
        $ticket->setTicketId(99);

        $this->assertSame('ABC-123', $ticket->getName());
        $this->assertSame('ABC-123', $ticket->getTicketNumber());
        $this->assertSame(5, $ticket->getTicketSystemId());
        $this->assertSame(120, $ticket->getEstimatedDuration());
        $this->assertSame('ABC-1', $ticket->getParentTicketNumber());
        $this->assertSame(99, $ticket->getTicketId());
    }
}


