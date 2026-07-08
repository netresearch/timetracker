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
use Tests\Traits\CreatesTestEntries;

use function array_values;
use function bin2hex;
use function hash;
use function json_decode;
use function random_bytes;

/**
 * GET /api/v2/day (ADR-022 Phase 2): the caller's own bookings for one day.
 *
 * @internal
 */
final class GetDayActionTest extends AbstractWebTestCase
{
    use CreatesTestEntries;

    public function testSessionRequestReturnsTheRequestedDay(): void
    {
        // The trait books 2026-07-06 for the session user.
        $this->createEntryFor('unittest', ticket: 'SA-11', description: 'day summary entry');

        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=2026-07-06');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('2026-07-06', $data['date']);
        self::assertIsList($data['entries']);
        self::assertNotEmpty($data['entries']);
        self::assertSame(60, $data['total_minutes']);
        self::assertSame(1, $data['count']);
    }

    public function testForeignEntriesAreNotIncluded(): void
    {
        // Another user's booking on the same day must not appear for the caller.
        $this->createEntryFor('developer', ticket: 'SA-12', description: 'foreign day entry');

        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=2026-07-06');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(0, $data['count']);
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/day?date=not-a-date');

        $this->assertStatusCode(422);
    }

    public function testTokenWithEntriesReadIsAuthorized(): void
    {
        $status = $this->requestWithToken('/api/v2/day', $this->mintToken(['entries:read']));

        self::assertSame(200, $status);
    }

    public function testTokenWithoutEntriesReadIsForbidden(): void
    {
        $status = $this->requestWithToken('/api/v2/day', $this->mintToken(['reporting:read']));

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
