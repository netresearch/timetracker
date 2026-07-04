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
 * Advertise the machine-readable API description on every response via Web Linking
 * (RFC 8288) headers, so an agent that fetches any page discovers the API without
 * parsing HTML: `service-desc` → the OpenAPI (RFC 8631), `api-catalog` → the
 * RFC 9727 link set. See docs/agent-readiness.md.
 */
final class DiscoveryLinkHeaderSubscriber implements EventSubscriberInterface
{
    private const array LINKS = [
        '</api.yml>; rel="service-desc"; type="application/yaml"',
        '</.well-known/api-catalog>; rel="api-catalog"',
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
        foreach (self::LINKS as $link) {
            $headers->set('Link', $link, false);
        }
    }
}
