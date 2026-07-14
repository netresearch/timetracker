<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\EventSubscriber\RequireFullAuthForImpersonationSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Unit tests for RequireFullAuthForImpersonationSubscriber: switch_user
 * (?simulateUserId=…) must be denied when the impersonating token came from
 * the REMEMBERME cookie instead of a full login (#587 security posture).
 *
 * @internal
 */
#[CoversClass(RequireFullAuthForImpersonationSubscriber::class)]
final class RequireFullAuthForImpersonationSubscriberTest extends TestCase
{
    private RequireFullAuthForImpersonationSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriber = new RequireFullAuthForImpersonationSubscriber(new AuthenticationTrustResolver());
    }

    public function testSubscribedToSwitchUserEvent(): void
    {
        self::assertSame(
            [SecurityEvents::SWITCH_USER => 'onSwitchUser'],
            RequireFullAuthForImpersonationSubscriber::getSubscribedEvents(),
        );
    }

    public function testRememberedOriginalTokenIsDenied(): void
    {
        $admin = new InMemoryUser('admin', null, ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH']);
        $originalToken = new RememberMeToken($admin, 'main');

        $this->expectException(AccessDeniedException::class);

        $this->subscriber->onSwitchUser($this->switchEvent($originalToken));
    }

    public function testFullyAuthenticatedOriginalTokenIsAllowed(): void
    {
        // No exception: the switch proceeds for a full login.
        $this->expectNotToPerformAssertions();

        $admin = new InMemoryUser('admin', null, ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH']);
        $originalToken = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        $this->subscriber->onSwitchUser($this->switchEvent($originalToken));
    }

    public function testExitingImpersonationIsNotGated(): void
    {
        // Leaving an impersonation dispatches the event with the ORIGINAL
        // (non-SwitchUser) token — even a remembered one must pass so the
        // admin can always back out.
        $this->expectNotToPerformAssertions();

        $admin = new InMemoryUser('admin', null, ['ROLE_ADMIN']);
        $exitEvent = new SwitchUserEvent(new Request(), $admin, new RememberMeToken($admin, 'main'));

        $this->subscriber->onSwitchUser($exitEvent);
    }

    private function switchEvent(TokenInterface $originalToken): SwitchUserEvent
    {
        $target = new InMemoryUser('developer', null, ['ROLE_USER']);
        $switchUserToken = new SwitchUserToken($target, 'main', ['ROLE_USER'], $originalToken);

        return new SwitchUserEvent(new Request(), $target, $switchUserToken);
    }
}
