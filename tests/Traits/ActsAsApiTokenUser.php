<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\ApiToken\ApiAccessToken;

use function array_values;

/**
 * Replaces the session token with a stateless PAT carrying the given scopes,
 * acting as a named fixture user — the same authentication state the /mcp
 * endpoint produces for tool calls.
 */
trait ActsAsApiTokenUser
{
    /**
     * @param list<string> $scopes
     */
    protected function useToken(array $scopes, string $username = 'unittest'): void
    {
        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        $token = new ApiAccessToken($user, array_values($user->getRoles()), $scopes);
        $container->get('security.token_storage')->setToken($token);
    }
}
