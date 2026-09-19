<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Set the security response headers that need no per-deployment decision.
 *
 * The application shipped none at all until now (issue #739): no framing
 * protection, no MIME-sniffing protection, no referrer policy. Escaping in Twig
 * and in the SolidJS SPA was the only layer between injected markup and
 * execution — see docs/threat-model.md, threat 6.
 *
 * A Content-Security-Policy is deliberately NOT set here. It needs nonces on
 * eight inline <script> blocks and an allowance for the corporate-navigation
 * iframe (APP_HEADER_URL), so it carries a test surface this subscriber does
 * not; it follows separately.
 *
 * These three are set on the application's own responses. Assets served
 * straight off disk by nginx do not pass through PHP and therefore do not
 * carry them; closing that gap means `add_header` in docker/nginx/, which is
 * per-deployment configuration.
 *
 * Existing values are never overwritten: a deployment that already sets one of
 * these at the reverse proxy keeps its own, and two conflicting values never
 * reach the browser.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * `X-Frame-Options: DENY` — nothing may frame TimeTracker. It does not stop
     * TimeTracker embedding others, so the #nrnavi corporate-navigation iframe
     * in partials/header.html.twig is unaffected.
     *
     * `X-Content-Type-Options: nosniff` — matters most on the JSON API, where a
     * browser guessing a content type from the body is how a response becomes a
     * script.
     *
     * `Referrer-Policy: same-origin` — stricter than the browser default
     * (strict-origin-when-cross-origin), which still leaks the origin outbound.
     * TimeTracker URLs carry entry, project and customer ids, and the header
     * iframe means a foreign origin is loaded on the page; a cross-origin
     * request should learn nothing about where it came from.
     */
    private const array HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }
}
