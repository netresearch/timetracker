<?php

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\AccessDeniedSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * Unit tests for AccessDeniedSubscriber.
 *
 * @internal
 */
#[CoversClass(AccessDeniedSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
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
            ->willReturnMap([
                ['_login', [], '/login'],
                ['_logout', [], '/logout'],
            ]);

        $this->subscriber = new AccessDeniedSubscriber(
            $this->security,
            $this->router,
        );
    }

    public function testSubscribedEvents(): void
    {
        $events = AccessDeniedSubscriber::getSubscribedEvents();

        self::assertArrayHasKey('kernel.exception', $events);
        // Priority 15 to run before ExceptionSubscriber (priority 10)
        self::assertSame(['onKernelException', 15], $events['kernel.exception']);
    }

    public function testIgnoresNonAccessDeniedExceptions(): void
    {
        $request = $this->createRequest(false);
        $event = $this->createExceptionEvent($request, new RuntimeException('Not access denied'));

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

    public function testRememberedUserNeedingFullAuthRedirectsToLogout(): void
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
        // Redirect to logout to properly clear session and all cookies
        self::assertSame('/logout', $response->getTargetUrl());
    }

    public function testFullyAuthenticatedUserWithoutPermissionLetsSymfonyHandle(): void
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

        // Subscriber should NOT set a response - let Symfony's error handling
        // render the error403.html.twig template with proper styling
        self::assertNull($event->getResponse());
    }

    public function testFullyAuthenticatedUserPrefersHtmlLetsSymfonyHandle(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Create request preferring HTML
        $request = $this->createRequestWithHeaders(false, [
            'Accept' => 'text/html,application/xhtml+xml',
        ]);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        // Should let Symfony handle it (render error403.html.twig)
        self::assertNull($event->getResponse());
    }

    public function testFullyAuthenticatedUserJsonAcceptHeaderReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        $request = $this->createRequestWithHeaders(false, [
            'Accept' => 'application/json',
        ]);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        /** @var array{error: string, message: string} $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Forbidden', $data['error']);
        self::assertSame('You are not allowed to perform this action.', $data['message']);
    }

    public function testFullyAuthenticatedUserJsonContentTypeReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Need to override Accept header to avoid HTML preference from Request::create()
        $request = $this->createRequestWithHeaders(false, [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
        ]);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testFullyAuthenticatedUserXmlHttpRequestReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Need to override Accept header to avoid HTML preference from Request::create()
        $request = $this->createRequestWithHeaders(false, [
            'Accept' => '*/*',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testFullyAuthenticatedUserApiPathReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Need to override Accept header to avoid HTML preference from Request::create()
        $request = $this->createRequestWithHeaders(false, ['Accept' => '*/*'], '/api/v1/users');
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testFullyAuthenticatedUserGetPathReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Path starts with /get, so should return JSON (no Accept header to avoid HTML preference)
        $request = $this->createRequestWithHeaders(false, ['Accept' => '*/*'], '/getEntries');
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testFullyAuthenticatedUserSavePathReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Path ends with /save, so should return JSON
        $request = $this->createRequestWithHeaders(false, ['Accept' => '*/*'], '/entry/save');
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testFullyAuthenticatedUserDeletePathReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Path ends with /delete, so should return JSON
        $request = $this->createRequestWithHeaders(false, ['Accept' => '*/*'], '/entry/delete');
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testFullyAuthenticatedUserGetAllPathReturnsJson(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $this->security
            ->method('getUser')
            ->willReturn($user);

        $this->security
            ->method('isGranted')
            ->with('IS_AUTHENTICATED_FULLY')
            ->willReturn(true);

        // Path starts with /getAll, so should return JSON
        $request = $this->createRequestWithHeaders(false, ['Accept' => '*/*'], '/getAllProjects');
        $event = $this->createExceptionEvent($request, new AccessDeniedException('Access Denied'));

        $this->subscriber->onKernelException($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
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
     * Create a request with custom headers and path.
     *
     * @param array<string, string> $headers
     */
    private function createRequestWithHeaders(bool $hasRememberMeCookie, array $headers = [], string $path = '/'): Request
    {
        $cookies = $hasRememberMeCookie ? ['REMEMBERME' => 'some-stale-token-value'] : [];
        $server = [];

        foreach ($headers as $key => $value) {
            // Symfony expects headers in HTTP_* format
            $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($key));
            $server[$headerKey] = $value;
        }

        // Set the request URI for path-based detection
        $server['REQUEST_URI'] = $path;

        return Request::create($path, 'GET', [], $cookies, [], $server);
    }

    /**
     * Create an exception event with the given request and exception.
     */
    private function createExceptionEvent(Request $request, Throwable $exception): ExceptionEvent
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
