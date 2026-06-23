<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * Functional coverage for GET /getContractHours, which feeds the /ui/month
 * "expected" column from the current user's contract.
 *
 * Fixture contracts for user 1 (unittest):
 *   - contract 1: 2020-01-01 … 2020-01-31, hours 0,1,2,3,4,5,0
 *   - contract 2: 2020-02-01 … open,       hours 0,1.1,2.2,3.3,4.4,5.5,0.5
 * User 5 (noContract) has none.
 *
 * @internal
 *
 * @coversNothing
 */
final class GetContractHoursActionTest extends AbstractWebTestCase
{
    public function testReturnsContractHoursForTheValidMonth(): void
    {
        $this->logInSession('unittest');
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getContractHours',
            ['year' => 2020, 'month' => 1],
        );

        $response = $this->client->getResponse();
        self::assertTrue($response->isSuccessful());
        $json = $this->getJsonResponse($response);

        // hours_0 = Sunday … hours_6 = Saturday (matches JS Date.getDay()).
        // JSON has no float/int distinction, so whole numbers decode as int;
        // assert loosely on value (the frontend reads them as numbers regardless).
        self::assertEquals(0, $json['hours_0']);
        self::assertEquals(1, $json['hours_1']);
        self::assertEquals(2, $json['hours_2']);
        self::assertEquals(3, $json['hours_3']);
        self::assertEquals(4, $json['hours_4']);
        self::assertEquals(5, $json['hours_5']);
        self::assertEquals(0, $json['hours_6']);
    }

    public function testPicksTheOpenEndedContractForALaterMonth(): void
    {
        $this->logInSession('unittest');
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getContractHours',
            ['year' => 2021, 'month' => 6],
        );

        $response = $this->client->getResponse();
        self::assertTrue($response->isSuccessful());
        $json = $this->getJsonResponse($response);

        // The open-ended contract 2 covers any month from 2020-02 onward.
        self::assertEqualsWithDelta(1.1, $json['hours_1'], 0.0001);
        self::assertEqualsWithDelta(5.5, $json['hours_5'], 0.0001);
        self::assertEqualsWithDelta(0.5, $json['hours_6'], 0.0001);
    }

    public function testFallsBackToEightHoursWhenUserHasNoContract(): void
    {
        $this->logInSession('noContract');
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getContractHours',
            ['year' => 2026, 'month' => 6],
        );

        $response = $this->client->getResponse();
        self::assertTrue($response->isSuccessful());
        $json = $this->getJsonResponse($response);

        foreach (['hours_0', 'hours_1', 'hours_2', 'hours_3', 'hours_4', 'hours_5', 'hours_6'] as $key) {
            self::assertEquals(8, $json[$key], $key . ' should default to 8');
        }
    }

    public function testFallsBackToEightHoursForAMonthBeforeAnyContract(): void
    {
        $this->logInSession('unittest');
        // Before contract 1 starts (2020-01-01): no contract is valid on 2019-12-01.
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/getContractHours',
            ['year' => 2019, 'month' => 12],
        );

        $response = $this->client->getResponse();
        self::assertTrue($response->isSuccessful());
        $json = $this->getJsonResponse($response);

        self::assertEquals(8, $json['hours_1']);
        self::assertEquals(8, $json['hours_6']);
    }
}
