<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class UserTicketsystemTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $userTicketsystem = new UserTicketsystem();
        $userTicketsystem->setId(42)
            ->setAccessToken('tok')
            ->setTokenSecret('sec')
            ->setAvoidConnection(true);

        self::assertSame(42, $userTicketsystem->getId());
        self::assertSame('tok', $userTicketsystem->getAccessToken());
        self::assertSame('sec', $userTicketsystem->getTokenSecret());
        self::assertTrue($userTicketsystem->getAvoidConnection());

        $ts = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $userTicketsystem->setTicketSystem($ts);
        $userTicketsystem->setUser($user);

        self::assertSame($ts, $userTicketsystem->getTicketSystem());
        self::assertSame($user, $userTicketsystem->getUser());
    }
}
