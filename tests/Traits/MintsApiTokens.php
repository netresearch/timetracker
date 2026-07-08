<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenService;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\HttpFoundation\Request;

use function array_values;
use function bin2hex;
use function hash;
use function random_bytes;

/**
 * Mints a scoped personal access token for the 'unittest' fixture user and
 * fires Bearer-authenticated GETs with it — for endpoint scope-gate tests.
 */
trait MintsApiTokens
{
    /**
     * @param list<string> $scopes
     */
    protected function mintToken(array $scopes): string
    {
        /** @var Registry $doctrine */
        $doctrine = static::getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();

        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => 'unittest']);
        self::assertInstanceOf(User::class, $user);

        $plaintext = ApiTokenService::PREFIX . bin2hex(random_bytes(32));
        $token = new ApiToken($user, 'test', hash('sha256', $plaintext), array_values($scopes), new DateTimeImmutable(), null, null, null);
        $entityManager->persist($token);
        $entityManager->flush();

        return $plaintext;
    }

    protected function requestWithToken(string $path, string $bearer): int
    {
        $this->client->request(Request::METHOD_GET, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer, 'HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse()->getStatusCode();
    }
}
