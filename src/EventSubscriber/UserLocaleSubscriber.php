<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Util\LocalizationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Sets the request + translator locale from the authenticated user's saved locale.
 *
 * The server-rendered chrome (templates/partials/header.html.twig) translates
 * through the translator locale. Without this subscriber every request kept
 * the configured default locale, so the header rendered in the app default
 * while the SPA content followed the user's saved locale (issue #618).
 *
 * Priority 4: below the security firewall (8), so the token exists when we
 * read the user. Symfony's LocaleAwareListener syncs the translator from the
 * request at priority 15 — BEFORE the firewall — so setting the request
 * locale alone can never reach the translator from a firewall-dependent
 * listener; the LocaleSwitcher call propagates it to all locale-aware
 * services directly. The request locale is still set for consumers that read
 * it (e.g. app.request.locale in error templates). Runs on sub-requests too,
 * so error pages rendered as sub-requests get the user's locale even when
 * the exception fired before this listener ran on the main request (e.g. a
 * 403 from the firewall at priority 8). A request dispatched with an
 * explicit `_locale` attribute is left untouched — error sub-requests carry
 * none (ErrorListener::duplicateRequest() replaces the attributes), so the
 * guard only skips requests that were given a fixed locale on purpose.
 */
final readonly class UserLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private LocaleSwitcher $localeSwitcher,
        private LocalizationService $localizationService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        $request = $requestEvent->getRequest();

        // An explicit `_locale` (route parameter or a sub-request dispatched
        // in a fixed locale) wins over the user's saved locale.
        if ($request->attributes->has('_locale')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Normalize on read like User::getSettings() does — legacy rows may
        // hold values outside the supported de/en/es/fr/ru set.
        $locale = $this->localizationService->normalizeLocale($user->getLocale());
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
