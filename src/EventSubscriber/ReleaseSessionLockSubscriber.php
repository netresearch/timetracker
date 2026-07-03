<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;

/**
 * Release the PHP session write-lock before the hot read-only data endpoints run.
 *
 * PHP holds an exclusive lock on the session from session_start() until the
 * response is written. The tracking page fires its grid queries in PARALLEL
 * (entries, customers, projects, activities, ticket systems, time summary), and
 * the lock serialises them server-side into a staircase — measured locally:
 * 15 ms alone, but 16/23/34/42 ms when fired together; in production the same
 * staircase showed as ~250-290 ms per request.
 *
 * These routes only READ the session (the firewall has already authenticated by
 * kernel.controller time), so the lock can be released as soon as the controller
 * is chosen. Explicit allowlist — endpoints that write session state (login,
 * 2FA, ceremonies) must never appear here.
 */
final readonly class ReleaseSessionLockSubscriber implements EventSubscriberInterface
{
    /**
     * Route names of read-only data endpoints fetched concurrently on page load.
     *
     * @var list<string>
     */
    private const array READ_ONLY_ROUTES = [
        '_getDataDays_attr',
        '_getCustomers_attr',
        '_getAllProjects_attr',
        '_getActivities_attr',
        '_getTicketSystems_attr',
        'time_summary_attr',
    ];

    public static function getSubscribedEvents(): array
    {
        // kernel.controller: the firewall (kernel.request) has already read the
        // session and authenticated; nothing before the controller writes it.
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $controllerEvent): void
    {
        if (!$controllerEvent->isMainRequest()) {
            return;
        }

        $request = $controllerEvent->getRequest();
        if (!$request->isMethod('GET') || !in_array($request->attributes->get('_route'), self::READ_ONLY_ROUTES, true)) {
            return;
        }

        if ($request->hasSession() && $request->getSession()->isStarted()) {
            // Persist + close: releases the lock so sibling requests proceed.
            $request->getSession()->save();
        }
    }
}
