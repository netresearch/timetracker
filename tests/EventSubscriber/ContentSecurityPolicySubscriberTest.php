<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\EventSubscriber\ContentSecurityPolicySubscriber;
use App\Security\Csp\ContentSecurityPolicyBuilder;
use App\Security\Csp\CspNonceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for ContentSecurityPolicySubscriber (issue #739).
 *
 * @internal
 */
#[CoversClass(ContentSecurityPolicySubscriber::class)]
final class ContentSecurityPolicySubscriberTest extends TestCase
{
    private const string REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    /**
     * The priority is not cosmetic: at 0 this ran before Symfony's
     * ResponseListener, which is what sets Content-Type on a Twig-rendered
     * response, so every HTML page was skipped. Below -128 it would be undone
     * by ErrorListener::removeCspHeader(), which strips the policy off error
     * pages on purpose.
     */
    public function testRunsAfterContentTypeIsSetAndBeforeTheErrorListenerStripsThePolicy(): void
    {
        $events = ContentSecurityPolicySubscriber::getSubscribedEvents();

        self::assertSame(['onKernelResponse', -100], $events[KernelEvents::RESPONSE]);
    }

    public function testAnHtmlResponseGetsTheReportOnlyPolicy(): void
    {
        $response = $this->dispatch($this->html());

        self::assertStringContainsString("default-src 'self'", (string) $response->headers->get(self::REPORT_ONLY));
    }

    /**
     * Report-only for the first round: a template that renders a <script>
     * without csp_nonce() would otherwise break that page outright instead of
     * reporting itself.
     */
    public function testTheEnforcingHeaderIsNotSet(): void
    {
        self::assertFalse($this->dispatch($this->html())->headers->has('Content-Security-Policy'));
    }

    /**
     * The header carries the same nonce the template rendered, which only holds
     * because both sides read CspNonceProvider.
     */
    public function testTheHeaderCarriesThisRequestsNonce(): void
    {
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);
        $provider = new CspNonceProvider($stack);
        $subscriber = new ContentSecurityPolicySubscriber(new ContentSecurityPolicyBuilder(''), $provider);

        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $this->html());
        $subscriber->onKernelResponse($event);

        self::assertStringContainsString(
            "'nonce-" . $provider->getNonce() . "'",
            (string) $event->getResponse()->headers->get(self::REPORT_ONLY),
        );
    }

    /**
     * A JSON response cannot execute script; a policy on it is bytes on every
     * request the SPA makes.
     */
    public function testAJsonResponseIsLeftAlone(): void
    {
        $response = $this->dispatch(new Response('{}', 200, ['Content-Type' => 'application/json']));

        self::assertFalse($response->headers->has(self::REPORT_ONLY));
    }

    /**
     * Two policies are intersected by the browser, which makes a violation
     * unattributable — a proxy that already set one keeps it.
     */
    public function testAnExistingPolicyIsNotReplaced(): void
    {
        $response = $this->html();
        $response->headers->set('Content-Security-Policy', "default-src 'none'");

        self::assertSame("default-src 'none'", $this->dispatch($response)->headers->get('Content-Security-Policy'));
        self::assertFalse($this->dispatch($response)->headers->has(self::REPORT_ONLY));
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $response = $this->dispatch($this->html(), HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has(self::REPORT_ONLY));
    }

    private function html(): Response
    {
        return new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function dispatch(Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $request = new Request();
        $stack = new RequestStack();
        $stack->push($request);

        $subscriber = new ContentSecurityPolicySubscriber(
            new ContentSecurityPolicyBuilder('https://nav.example.com/menu'),
            new CspNonceProvider($stack),
        );

        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, $type, $response);
        $subscriber->onKernelResponse($event);

        return $event->getResponse();
    }
}
