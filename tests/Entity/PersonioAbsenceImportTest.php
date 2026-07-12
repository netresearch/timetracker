<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\PersonioAbsenceImport;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PersonioAbsenceImportTest extends TestCase
{
    public function testFluentSetters(): void
    {
        $user = new User();
        $record = new PersonioAbsenceImport();
        $record->setUser($user)
            ->setAbsenceId('abs-abc')
            ->setEntryIds([501, 502])
            ->setSignature(['start' => '2026-07-06T00:00:00.000', 'end' => '2026-07-08T00:00:00.000', 'typeId' => 'type-vac'])
            ->setLastImportedAt(new DateTimeImmutable('2026-07-06 09:00:00'));

        self::assertSame($user, $record->getUser());
        self::assertSame('abs-abc', $record->getAbsenceId());
        self::assertSame([501, 502], $record->getEntryIds());
        self::assertSame('type-vac', $record->getSignature()['typeId']);
        self::assertNull($record->getLastSyncRun());
    }
}
