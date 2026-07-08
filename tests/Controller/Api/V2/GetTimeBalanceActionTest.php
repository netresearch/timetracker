<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenService;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function array_values;
use function assert;
use function bin2hex;
use function hash;
use function is_array;
use function json_decode;
use function random_bytes;

/**
 * GET /api/v2/time-balance (ADR-022): session and PAT access, scope gate,
 * and the DTO wire shape.
 *
 * @internal
 */
final class GetTimeBalanceActionTest extends AbstractWebTestCase
{
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

    /**
     * @param list<string> $scopes
     */
    private function mintToken(array $scopes): string
    {
        /** @var Registry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();

        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => 'unittest']);
        self::assertInstanceOf(User::class, $user);

        $plaintext = ApiTokenService::PREFIX . bin2hex(random_bytes(32));
        $token = new ApiToken($user, 'test', hash('sha256', $plaintext), array_values($scopes), new DateTimeImmutable(), null, null, null);
        $entityManager->persist($token);
        $entityManager->flush();

        return $plaintext;
    }

    private function requestWithToken(string $path, string $bearer): int
    {
        $this->client->request(Request::METHOD_GET, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer, 'HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse()->getStatusCode();
    }
}
