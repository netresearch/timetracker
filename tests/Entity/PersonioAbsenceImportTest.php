<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\PersonioAbsenceImport;
use App\Entity\SyncRun;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The record that makes a Personio import idempotent: which entries it created
 * for an absence, and the signature of the absence they were built from.
 *
 * @internal
 */
#[CoversClass(PersonioAbsenceImport::class)]
final class PersonioAbsenceImportTest extends TestCase
{
    public function testAFreshRecordHasNoIdAndEmptyCollections(): void
    {
        $personioAbsenceImport = new PersonioAbsenceImport();

        self::assertNull($personioAbsenceImport->getId());
        self::assertNull($personioAbsenceImport->getUser());
        self::assertNull($personioAbsenceImport->getAbsenceId());
        self::assertNull($personioAbsenceImport->getLastImportedAt());
        self::assertNull($personioAbsenceImport->getLastSyncRun());
        self::assertSame([], $personioAbsenceImport->getEntryIds());
        self::assertSame([], $personioAbsenceImport->getSignature());
    }

    public function testTheOwningUserRoundTrips(): void
    {
        $user = new User();
        $personioAbsenceImport = new PersonioAbsenceImport();

        self::assertSame($personioAbsenceImport, $personioAbsenceImport->setUser($user));
        self::assertSame($user, $personioAbsenceImport->getUser());
    }

    public function testTheAbsenceIdRoundTrips(): void
    {
        $personioAbsenceImport = new PersonioAbsenceImport();
        $personioAbsenceImport->setAbsenceId('absence-4711');

        self::assertSame('absence-4711', $personioAbsenceImport->getAbsenceId());
    }

    public function testTheCreatedEntryIdsRoundTrip(): void
    {
        $personioAbsenceImport = new PersonioAbsenceImport();
        $personioAbsenceImport->setEntryIds([12, 13, 14]);

        self::assertSame([12, 13, 14], $personioAbsenceImport->getEntryIds());
    }

    public function testTheSignatureRoundTripsWithItsNullFields(): void
    {
        $signature = [
            'start_date' => '2026-09-21T09:00:00+02:00',
            'end_date' => '2026-09-21T17:00:00+02:00',
            'half_day_start' => null,
            'time_off_type_id' => '77',
        ];

        $personioAbsenceImport = new PersonioAbsenceImport();
        $personioAbsenceImport->setSignature($signature);

        self::assertSame($signature, $personioAbsenceImport->getSignature());
    }

    public function testTheImportTimestampRoundTrips(): void
    {
        $lastImportedAt = new DateTimeImmutable('2026-09-21 06:30:00');
        $personioAbsenceImport = new PersonioAbsenceImport();
        $personioAbsenceImport->setLastImportedAt($lastImportedAt);

        self::assertSame($lastImportedAt, $personioAbsenceImport->getLastImportedAt());
    }

    public function testTheSettersChain(): void
    {
        $user = new User();
        $personioAbsenceImport = new PersonioAbsenceImport();
        $personioAbsenceImport->setUser($user)
            ->setAbsenceId('abs-abc')
            ->setEntryIds([501, 502])
            ->setSignature(['start' => '2026-07-06T00:00:00.000', 'end' => '2026-07-08T00:00:00.000', 'typeId' => 'type-vac'])
            ->setLastImportedAt(new DateTimeImmutable('2026-07-06 09:00:00'));

        self::assertSame($user, $personioAbsenceImport->getUser());
        self::assertSame('abs-abc', $personioAbsenceImport->getAbsenceId());
        self::assertSame([501, 502], $personioAbsenceImport->getEntryIds());
        self::assertSame('type-vac', $personioAbsenceImport->getSignature()['typeId']);
        self::assertNull($personioAbsenceImport->getLastSyncRun());
    }

    public function testTheLastSyncRunCanBeSetAndCleared(): void
    {
        $syncRun = new SyncRun();
        $personioAbsenceImport = new PersonioAbsenceImport();

        $personioAbsenceImport->setLastSyncRun($syncRun);
        self::assertSame($syncRun, $personioAbsenceImport->getLastSyncRun());

        // The join column is ON DELETE SET NULL, so the entity has to accept null.
        $personioAbsenceImport->setLastSyncRun(null);
        self::assertNull($personioAbsenceImport->getLastSyncRun());
    }
}
