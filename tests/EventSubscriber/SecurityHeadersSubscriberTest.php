<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for SecurityHeadersSubscriber (issue #739).
 *
 * @internal
 */
#[CoversClass(SecurityHeadersSubscriber::class)]
final class SecurityHeadersSubscriberTest extends TestCase
{
    private SecurityHeadersSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscriber = new SecurityHeadersSubscriber();
    }

    public function testSubscribesToTheResponseEvent(): void
    {
        self::assertSame(
            [KernelEvents::RESPONSE => 'onKernelResponse'],
            SecurityHeadersSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * The values are asserted literally rather than read back from the subject,
     * so weakening one — DENY to SAMEORIGIN, same-origin to no-referrer-when-
     * downgrade — fails here instead of passing against itself.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function expectedHeaders(): iterable
    {
        yield 'framing' => ['X-Frame-Options', 'DENY'];
        yield 'mime sniffing' => ['X-Content-Type-Options', 'nosniff'];
        yield 'referrer' => ['Referrer-Policy', 'same-origin'];
    }

    #[DataProvider('expectedHeaders')]
    public function testSetsTheHeaderOnAMainRequest(string $name, string $value): void
    {
        $response = $this->dispatch(new Response());

        self::assertSame($value, $response->headers->get($name));
    }

    public function testSetsTheHeadersOnAJsonResponseToo(): void
    {
        $response = $this->dispatch(new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']));

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * A reverse proxy that already sets one of these keeps its own value, so the
     * browser never receives two conflicting ones.
     */
    public function testDoesNotOverwriteAnExistingValue(): void
    {
        $response = $this->dispatch(new Response('', 200, ['X-Frame-Options' => 'SAMEORIGIN']));

        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * A sub-request renders a fragment; its headers are discarded, and touching
     * them would only mask which listener set what on the main response.
     */
    public function testLeavesASubRequestAlone(): void
    {
        $response = $this->dispatch(new Response(), HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('X-Frame-Options'));
        self::assertFalse($response->headers->has('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('Referrer-Policy'));
    }

    private function dispatch(Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $event = new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            new Request(),
            $type,
            $response,
        );

        $this->subscriber->onKernelResponse($event);

        return $event->getResponse();
    }
}
