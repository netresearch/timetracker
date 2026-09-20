<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Csp\ContentSecurityPolicyBuilder;
use App\Security\Csp\CspNonceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function str_contains;

/**
 * Attach the Content-Security-Policy in report-only mode (issue #739).
 *
 * Report-only on purpose, for one round: the policy is nonce-based and refuses
 * inline script, so a single template that renders a <script> without
 * `csp_nonce()` would break that page outright. Report-only turns that failure
 * into a violation report instead, and e2e/csp.spec.ts is what reads those
 * reports — there is no report-uri collector, so the browser event is the
 * signal. Flipping to the enforcing header is a one-word change once the suite
 * has been green across a release.
 *
 * Only HTML responses carry it. A JSON API response cannot execute script, and
 * a policy on it would only add bytes to every request the SPA makes.
 */
final readonly class ContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ContentSecurityPolicyBuilder $builder,
        private CspNonceProvider $nonceProvider,
    ) {
    }

    /**
     * Priority -100, and the number is load-bearing in both directions.
     *
     * Symfony's own ResponseListener runs at 0 and is what sets Content-Type on
     * a Twig-rendered response; at priority 0 this subscriber ran before it,
     * saw no Content-Type, and silently skipped every HTML page — the header
     * appeared only on responses that carried their own type, such as a
     * redirect. Below 0, the type is there.
     *
     * Not below -128 either: ErrorListener::removeCspHeader() strips the policy
     * off error responses at that priority so the exception page renders, and
     * setting the header after it would defeat that on purpose-built error
     * pages.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -100]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        // A reverse proxy that already sets a policy keeps it; two policies are
        // intersected by the browser, which makes a violation unattributable.
        if ($headers->has('Content-Security-Policy') || $headers->has('Content-Security-Policy-Report-Only')) {
            return;
        }

        $contentType = $headers->get('Content-Type', '');
        if (!str_contains((string) $contentType, 'text/html')) {
            return;
        }

        $headers->set(
            'Content-Security-Policy-Report-Only',
            $this->builder->build($this->nonceProvider->getNonce()),
        );
    }
}
