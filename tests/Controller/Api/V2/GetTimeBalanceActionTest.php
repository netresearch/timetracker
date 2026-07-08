<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\MintsApiTokens;

use function assert;
use function is_array;
use function json_decode;

/**
 * GET /api/v2/time-balance (ADR-022): session and PAT access, scope gate,
 * and the DTO wire shape.
 *
 * @internal
 */
final class GetTimeBalanceActionTest extends AbstractWebTestCase
{
    use MintsApiTokens;

    public function testSessionRequestReturnsBalance(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/time-balance');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('warnings', $data);
        foreach (['today', 'week', 'month'] as $period) {
            self::assertArrayHasKey($period, $data);
            assert(is_array($data[$period]));
            foreach (['ist', 'soll_total', 'soll_so_far', 'diff', 'status'] as $key) {
                self::assertArrayHasKey($key, $data[$period], $period);
            }
        }
    }

    public function testTokenWithReportingReadIsAuthorized(): void
    {
        $status = $this->requestWithToken('/api/v2/time-balance', $this->mintToken(['reporting:read']));

        self::assertSame(200, $status);
    }

    public function testTokenWithoutReportingReadIsForbidden(): void
    {
        $status = $this->requestWithToken('/api/v2/time-balance', $this->mintToken(['entries:read']));

        self::assertSame(403, $status);
    }
}
