<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use PHPUnit\Framework\TestCase;

class UserTicketsystemTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $uts = new UserTicketsystem();
        $uts->setId(42)
            ->setAccessToken('tok')
            ->setTokenSecret('sec')
            ->setAvoidConnection(true);

        $this->assertSame(42, $uts->getId());
        $this->assertSame('tok', $uts->getAccessToken());
        $this->assertSame('sec', $uts->getTokenSecret());
        $this->assertTrue($uts->getAvoidConnection());

        $ts = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $uts->setTicketSystem($ts);
        $uts->setUser($user);

        $this->assertSame($ts, $uts->getTicketSystem());
        $this->assertSame($user, $uts->getUser());
    }
}


