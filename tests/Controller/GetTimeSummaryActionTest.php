<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Service\ClockInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * Functional coverage for GET /getTimeSummary, which feeds the header worktime
 * badges with worked minutes (IST) and — since the sidebar footer redesign — the
 * expected minutes (SOLL) for the same periods.
 *
 * Values depend on "today", so this asserts the payload shape and invariants
 * (present, numeric, non-negative) rather than exact figures.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetTimeSummaryActionTest extends AbstractWebTestCase
{
    public function testReturnsWorkedAndTargetMinutesPerPeriod(): void
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_GET, '/getTimeSummary');

        $response = $this->client->getResponse();
        self::assertTrue($response->isSuccessful());
        $json = $this->getJsonResponse($response);
        self::assertIsArray($json);

        foreach (['today', 'week', 'month'] as $period) {
            self::assertArrayHasKey($period, $json);
            $data = $json[$period];
            self::assertIsArray($data);
            self::assertArrayHasKey('duration', $data, $period . ' must carry worked minutes (IST)');
            self::assertArrayHasKey('target', $data, $period . ' must carry expected minutes (SOLL)');
            self::assertIsNumeric($data['duration']);
            self::assertIsNumeric($data['target']);
            self::assertGreaterThanOrEqual(0, $data['target'], $period . ' target is never negative');
        }

        // NB: no month ≥ week ≥ today ordering — week-to-date (Mon→today) can exceed
        // month-to-date early in a month, when the week reaches into the prior month
        // (e.g. the 1st on a Wednesday: month = today only, week = Mon–Wed).
    }

    /**
     * ADR-025 §5: the header IST is human labour only — an agent wall-clock
     * entry on the same day must not inflate the worked-minutes total.
     */
    public function testWorkedMinutesCountHumanSourceOnly(): void
    {
        $this->logInSession('unittest');

        $container = self::getContainer();
        $entityManager = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $today = DateTime::createFromInterface($clock->today());

        $user = $entityManager->getRepository(User::class)->find(1);
        self::assertInstanceOf(User::class, $user, 'unittest fixture user missing');

        $base = $this->todayDuration();

        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::HUMAN)->setDuration(60)
                ->setDay(clone $today)->setStart(new DateTime('08:00'))->setEnd(new DateTime('09:00')),
        );
        $entityManager->persist(
            new Entry()->setUser($user)->setSource(EntrySource::AGENT)->setDuration(180)
                ->setDay(clone $today)->setStart(new DateTime('09:00'))->setEnd(new DateTime('12:00')),
        );
        $entityManager->flush();

        // IST rises by the human 60 only, not by 240.
        self::assertSame($base + 60, $this->todayDuration());
    }

    private function todayDuration(): int
    {
        $this->client->request(Request::METHOD_GET, '/getTimeSummary');
        $json = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($json);
        self::assertArrayHasKey('today', $json);
        self::assertIsArray($json['today']);
        self::assertArrayHasKey('duration', $json['today']);
        self::assertIsNumeric($json['today']['duration']);

        return (int) $json['today']['duration'];
    }
}
