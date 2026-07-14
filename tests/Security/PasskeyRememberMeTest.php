<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\EventListener\CheckRememberMeConditionsListener;

/**
 * Characterization test for the passkey "Stay logged in" contract (#587).
 *
 * The webauthn assertion travels to /login/passkey as a raw JSON body, which
 * Symfony's CheckRememberMeConditionsListener never inspects — it reads the
 * `_remember_me` parameter from request attributes, the query string, or form
 * fields only. The SPA therefore appends `?_remember_me=1` to the result URL
 * (frontend/src/lib/passkeys.ts). These tests pin that mechanism with the real
 * listener and its shipped defaults (always_remember_me=false, parameter
 * `_remember_me` — security.yaml overrides neither): if a Symfony upgrade
 * stops honoring the query parameter for POST logins, passkey remember-me
 * breaks and this test flags it.
 *
 * @internal
 *
 * @coversNothing
 */
final class PasskeyRememberMeTest extends TestCase
{
    public function testQueryParameterEnablesRememberMeForJsonBodyLogin(): void
    {
        $badge = $this->dispatchLoginSuccess('/login/passkey?_remember_me=1');

        self::assertTrue($badge->isEnabled(), '?_remember_me=1 on the ceremony result URL must enable remember-me');
    }

    public function testWithoutQueryParameterRememberMeStaysDisabled(): void
    {
        $badge = $this->dispatchLoginSuccess('/login/passkey');

        self::assertFalse($badge->isEnabled(), 'a passkey login without the parameter must not opt in to remember-me');
    }

    /**
     * Run the real listener over a login-success event for a JSON-body POST —
     * the shape of the SPA's webauthn result request.
     */
    private function dispatchLoginSuccess(string $uri): RememberMeBadge
    {
        $user = new InMemoryUser('unittest', null);
        $rememberMeBadge = new RememberMeBadge();
        $passport = new SelfValidatingPassport(
            new UserBadge('unittest', static fn (): InMemoryUser => $user),
            [$rememberMeBadge],
        );

        $request = Request::create(
            $uri,
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"id":"assertion"}',
        );

        $loginSuccessEvent = new LoginSuccessEvent(
            self::createStub(AuthenticatorInterface::class),
            $passport,
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            $request,
            null,
            'main',
        );

        new CheckRememberMeConditionsListener()->onSuccessfulLogin($loginSuccessEvent);

        return $rememberMeBadge;
    }
}
