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
 * Attach the Content-Security-Policy (issue #739).
 *
 * Enforcing since the report-only round produced data: e2e/csp.spec.ts drove
 * the login page, the application shell, the worklog, the evaluation, the
 * admin area and the three settings sections in CI and reported zero
 * violations. Until that run existed this header was
 * Content-Security-Policy-Report-Only, because a nonce-based policy refuses
 * inline script and a single template rendering a <script> without
 * `csp_nonce()` would have broken that page outright.
 *
 * `frame-ancestors 'none'` is live now, which supersedes the X-Frame-Options
 * SecurityHeadersSubscriber sets — that header stays for the sake of anything
 * that reads it and never becomes the weaker of the two.
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
            'Content-Security-Policy',
            $this->builder->build($this->nonceProvider->getNonce()),
        );
    }
}
