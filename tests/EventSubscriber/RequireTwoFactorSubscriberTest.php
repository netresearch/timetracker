<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\RequireTwoFactorSubscriber;
use App\Repository\WebauthnCredentialRepository;
use App\Service\Security\TwoFactorStatusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * @internal
 */
#[CoversClass(RequireTwoFactorSubscriber::class)]
final class RequireTwoFactorSubscriberTest extends TestCase
{
    private const string JSON_ACCEPT = 'application/json';
    private const string HTML_ACCEPT = 'text/html';

    public function testDoesNothingWhenTheFlagIsOff(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: false),
            path: '/getAllProjects',
        );

        self::assertNull($event->getResponse());
    }

    public function testDoesNothingForAnUnauthenticatedRequest(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true, user: null),
            path: '/getAllProjects',
        );

        self::assertNull($event->getResponse());
    }

    public function testDefersToSchebWhileALoginIsResolvingItsSecondFactor(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true, twoFactorInProgress: true),
            path: '/getAllProjects',
        );

        self::assertNull($event->getResponse());
    }

    public function testAllowsAUserWhoAlreadyHasASecondFactor(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true, user: self::userWithTotp()),
            path: '/getAllProjects',
        );

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true),
            path: '/getAllProjects',
            requestType: HttpKernelInterface::SUB_REQUEST,
        );

        self::assertNull($event->getResponse());
    }

    #[DataProvider('allowlistedPaths')]
    public function testAllowsTheEnrolmentAndShellSurface(string $path): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true),
            path: $path,
        );

        self::assertNull($event->getResponse(), $path . ' must stay reachable');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowlistedPaths(): iterable
    {
        yield 'SPA shell' => ['/ui/tracking'];
        yield 'TOTP start' => ['/settings/2fa/totp/start'];
        yield 'TOTP confirm' => ['/settings/2fa/totp/confirm'];
        yield 'passkey list' => ['/settings/security/passkeys/list'];
        yield 'passkey register' => ['/settings/security/passkeys/options'];
        yield 'logout' => ['/logout'];
        yield 'login options' => ['/login/options'];
        yield '2fa check' => ['/2fa_check'];
        yield 'status check' => ['/status/check'];
    }

    public function testBlocksAnApiCallWithA403Json(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true),
            path: '/getAllProjects',
            accept: self::JSON_ACCEPT,
        );

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('TwoFactorRequired', (string) $response->getContent());
    }

    public function testBlocksTheSettingsSaveEndpoint(): void
    {
        // /settings/save shares the /settings prefix but is NOT enrolment — blocked.
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true),
            path: '/settings/save',
            accept: self::JSON_ACCEPT,
        );

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[DataProvider('substringBoundaryPaths')]
    public function testDoesNotAllowlistAMereSubstringPrefix(string $path): void
    {
        // The strict boundary must not let a route that only SHARES a leading
        // substring with an allowlisted prefix slip through.
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true),
            path: $path,
            accept: self::JSON_ACCEPT,
        );

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response, $path . ' must be blocked');
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function substringBoundaryPaths(): iterable
    {
        yield '2fa bypass' => ['/settings/2fa_bypass'];
        yield 'status admin' => ['/status_admin'];
        yield 'uixyz' => ['/uixyz'];
        yield 'logout-all' => ['/logout-all'];
    }

    public function testRedirectsAnHtmlNavigationToTheGate(): void
    {
        $event = $this->dispatch(
            subscriber: $this->subscriber(required: true),
            path: '/some/legacy/page',
            accept: self::HTML_ACCEPT,
        );

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/ui/', $response->getTargetUrl());
    }

    /**
     * Uses the REAL status service (it is final) with a stubbed passkey repo, so
     * "has a second factor" is driven by the user's actual state: a fresh User has
     * none; {@see userWithTotp()} has one.
     */
    private function subscriber(
        bool $required,
        ?User $user = new User(),
        bool $twoFactorInProgress = false,
    ): RequireTwoFactorSubscriber {
        $security = self::createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn($twoFactorInProgress);

        $credentials = self::createStub(WebauthnCredentialRepository::class);
        $credentials->method('countByUserHandle')->willReturn(0);
        $status = new TwoFactorStatusService($credentials);

        $router = self::createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/ui/');

        return new RequireTwoFactorSubscriber($security, $status, $router, $required);
    }

    private static function userWithTotp(): User
    {
        $user = new User();
        $user->setTotpSecret('ENC(x)', 'x');

        return $user;
    }

    private function dispatch(
        RequireTwoFactorSubscriber $subscriber,
        string $path,
        string $accept = self::JSON_ACCEPT,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        $request = Request::create($path);
        $request->headers->set('Accept', $accept);

        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);
        $subscriber->onKernelRequest($event);

        return $event;
    }
}
