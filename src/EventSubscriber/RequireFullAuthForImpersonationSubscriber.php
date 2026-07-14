<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Locks user impersonation (switch_user, ?simulateUserId=…) to full-fledged
 * logins. The firewall's SwitchUserListener only checks ROLE_ALLOWED_TO_SWITCH,
 * which a session resumed from the REMEMBERME cookie still carries — since the
 * catch-all access rule accepts remembered sessions (#587), a stolen 30-day
 * cookie of an admin would otherwise be enough to act as any other user. The
 * thrown exception surfaces as an AccessDeniedException BEFORE the switch
 * token is stored, and Symfony's ExceptionListener then sends the remembered
 * admin to the login form for a fresh authentication.
 */
final readonly class RequireFullAuthForImpersonationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        // No interface alias exists in this app (scheb decorates the concrete
        // service), so reference the decorated resolver by id.
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private AuthenticationTrustResolverInterface $trustResolver,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::SWITCH_USER => 'onSwitchUser',
        ];
    }

    public function onSwitchUser(SwitchUserEvent $switchUserEvent): void
    {
        $token = $switchUserEvent->getToken();

        // Exiting an impersonation restores the original token (not a
        // SwitchUserToken) — nothing to gate there.
        if (!$token instanceof SwitchUserToken) {
            return;
        }

        if (!$this->trustResolver->isFullFledged($token->getOriginalToken())) {
            throw new AccessDeniedException('Impersonation requires a full login, not a remembered session.');
        }
    }
}
