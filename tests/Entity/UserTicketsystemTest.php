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
        $userTicketsystem = new UserTicketsystem();
        $userTicketsystem->setId(42)
            ->setAccessToken('tok')
            ->setTokenSecret('sec')
            ->setAvoidConnection(true);

        $this->assertSame(42, $userTicketsystem->getId());
        $this->assertSame('tok', $userTicketsystem->getAccessToken());
        $this->assertSame('sec', $userTicketsystem->getTokenSecret());
        $this->assertTrue($userTicketsystem->getAvoidConnection());

        $ts = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $userTicketsystem->setTicketSystem($ts);
        $userTicketsystem->setUser($user);

        $this->assertSame($ts, $userTicketsystem->getTicketSystem());
        $this->assertSame($user, $userTicketsystem->getUser());
    }
}
