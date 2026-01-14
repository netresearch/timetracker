<?php

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\AccessDeniedSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @internal
 *
 * @covers \App\EventSubscriber\AccessDeniedSubscriber
 */
final class AccessDeniedSubscriberTest extends TestCase
{
    private MockObject&Security $security;
    private MockObject&RouterInterface $router;
    private AccessDeniedSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->security = $this->createMock(Security::class);
        $this->router = $this->createMock(RouterInterface::class);

        $this->router
            ->method('generate')
            ->with('_login')
            ->willReturn('/login');

        $this->subscriber = new AccessDeniedSubscriber(
            $this->security,
            $this->router,
        );
    }

    public function testSubscribedEvents(): void
    {
        $events = AccessDeniedSubscriber::getSubscribedEvents();

        self::assertArrayHasKey('kernel.exception', $events);
        self::assertSame(['onKernelException', 5], $events['kernel.exception']);
    }

    public function testIgnoresNonAccessDeniedExceptions(): void
    {
        $request = $this->createRequest(false);
        $event = $this->createExceptionEvent($request, new \RuntimeException('Not access denied'));

        $this->subscriber->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    public function testUnauthenticatedUserRedirectsToLogin(): void
    {
        $this->security
            ->method('getUser')
            ->willReturn(null);

        $request = $this->createRequest(false);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testUnauthenticatedUserWithStaleRememberMeCookieClearsIt(): void
    {
        $this->security
            ->method('getUser')
            ->willReturn(null);

        $request = $this->createRequest(true);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());

        // Verify the REMEMBERME cookie is cleared
        $cookies = $response->headers->getCookies();
        $rememberMeCookie = null;
        foreach ($cookies as $cookie) {
            if ('REMEMBERME' === $cookie->getName()) {
                $rememberMeCookie = $cookie;
                break;
            }
        }

        self::assertNotNull($rememberMeCookie, 'REMEMBERME cookie should be cleared');
        self::assertTrue($rememberMeCookie->isCleared(), 'REMEMBERME cookie should be marked as cleared');
    }

    public function testRememberedUserNeedingFullAuthRedirectsToLogin(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        // User is authenticated via remember_me but NOT fully authenticated
        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(false);

        $request = $this->createRequest(true);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testFullyAuthenticatedUserWithoutPermissionGets403(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        // User is fully authenticated
        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        $request = $this->createRequest(false);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('You are not allowed to perform this action.', $response->getContent());
    }

    /**
     * Create a real request with optional REMEMBERME cookie.
     */
    private function createRequest(bool $hasRememberMeCookie): Request
    {
        $cookies = $hasRememberMeCookie ? ['REMEMBERME' => 'some-stale-token-value'] : [];

        return new Request([], [], [], $cookies);
    }

    /**
     * Create an exception event with the given request and exception.
     */
    private function createExceptionEvent(Request $request, \Throwable $exception): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
