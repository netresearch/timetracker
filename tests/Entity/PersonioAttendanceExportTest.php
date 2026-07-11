<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\PersonioAttendanceExport;
use App\Entity\User;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PersonioAttendanceExportTest extends TestCase
{
    public function testFluentSetters(): void
    {
        $user = new User();
        $export = new PersonioAttendanceExport();
        $export->setUser($user)
            ->setDay(new DateTime('2026-07-01'))
            ->setPeriodIds(['1001', '1002'])
            ->setBasePayload([['start' => 100, 'end' => 200]])
            ->setLastExportedAt(new DateTimeImmutable('2026-07-01 12:00:00'));

        self::assertSame($user, $export->getUser());
        self::assertSame(['1001', '1002'], $export->getPeriodIds());
        self::assertSame([['start' => 100, 'end' => 200]], $export->getBasePayload());
    }
}
