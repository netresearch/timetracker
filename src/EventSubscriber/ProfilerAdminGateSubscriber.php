<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Turns the profiler on only for admin requests. Registered solely in the
 * `profiling` env (config/services_profiling.yaml), where the profiler is
 * configured with `collect: false`; this is the only switch that enables
 * collection — and therefore the web-debug-toolbar — and only for ROLE_ADMIN.
 * Runs after the firewall (priority 8) so the security token is resolved.
 */
final readonly class ProfilerAdminGateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        #[Autowire(service: 'profiler')]
        private Profiler $profiler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // isGranted() resolves the lazy firewall token; false for anonymous
        // requests (e.g. the login page), so credentials are never profiled.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $this->profiler->enable();
        }
    }
}
