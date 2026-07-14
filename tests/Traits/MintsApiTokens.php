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
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * Mints a scoped personal access token for the 'unittest' fixture user and
 * fires Bearer-authenticated GETs with it — for endpoint scope-gate tests.
 */
trait MintsApiTokens
{
    private const string BEARER_PREFIX = 'Bearer ';

    private const string JSON_MIME = 'application/json';

    /**
     * Server vars for a Bearer-authenticated request; JSON body vars on demand.
     *
     * @return array<string, string>
     */
    private function bearerServerVars(string $bearer, bool $jsonBody = false): array
    {
        $vars = ['HTTP_AUTHORIZATION' => self::BEARER_PREFIX . $bearer, 'HTTP_ACCEPT' => self::JSON_MIME];
        if ($jsonBody) {
            $vars['CONTENT_TYPE'] = self::JSON_MIME;
        }

        return $vars;
    }

    /**
     * @param list<string> $scopes
     */
    protected function mintToken(array $scopes, string $username = 'unittest'): string
    {
        /** @var Registry $doctrine */
        $doctrine = static::getContainer()->get('doctrine');
        $entityManager = $doctrine->getManager();

        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        $plaintext = ApiTokenService::PREFIX . bin2hex(random_bytes(32));
        $token = new ApiToken($user, 'test', hash('sha256', $plaintext), array_values($scopes), new DateTimeImmutable(), null, null, null);
        $entityManager->persist($token);
        $entityManager->flush();

        return $plaintext;
    }

    protected function requestWithToken(string $path, string $bearer): int
    {
        $this->client->request(Request::METHOD_GET, $path, [], [], $this->bearerServerVars($bearer));

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed> $json
     */
    protected function postJsonWithToken(string $path, string $bearer, array $json): int
    {
        $this->client->request(
            Request::METHOD_POST,
            $path,
            [],
            [],
            $this->bearerServerVars($bearer, jsonBody: true),
            json_encode($json, JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed> $json
     */
    protected function patchJsonWithToken(string $path, string $bearer, array $json): int
    {
        $this->client->request(
            Request::METHOD_PATCH,
            $path,
            [],
            [],
            $this->bearerServerVars($bearer, jsonBody: true),
            json_encode($json, JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse()->getStatusCode();
    }
}
