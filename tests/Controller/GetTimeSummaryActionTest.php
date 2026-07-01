<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

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

        $targets = [];
        foreach (['today', 'week', 'month'] as $period) {
            self::assertArrayHasKey($period, $json);
            $data = $json[$period];
            self::assertIsArray($data);
            self::assertArrayHasKey('duration', $data, $period . ' must carry worked minutes (IST)');
            self::assertArrayHasKey('target', $data, $period . ' must carry expected minutes (SOLL)');
            self::assertIsNumeric($data['duration']);
            self::assertIsNumeric($data['target']);
            self::assertGreaterThanOrEqual(0, $data['target'], $period . ' target is never negative');
            $targets[$period] = $data['target'];
        }

        // SOLL accumulates over longer periods: month-to-date ≥ week-to-date ≥ today.
        self::assertGreaterThanOrEqual($targets['week'], $targets['month']);
        self::assertGreaterThanOrEqual($targets['today'], $targets['week']);
    }

    public function testRedirectsToLoginWhenNotAuthenticated(): void
    {
        $this->client->request(Request::METHOD_GET, '/getTimeSummary');

        self::assertTrue($this->client->getResponse()->isRedirection());
    }
}
