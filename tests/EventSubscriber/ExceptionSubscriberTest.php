<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ExceptionSubscriber;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

#[CoversClass(ExceptionSubscriber::class)]
final class ExceptionSubscriberTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private HttpKernelInterface&MockObject $kernel;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    private function createSubscriber(string $environment = 'prod'): ExceptionSubscriber
    {
        return new ExceptionSubscriber($this->logger, $environment);
    }

    private function createExceptionEvent(Request $request, Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = ExceptionSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        $this->assertSame(['onKernelException', 10], $events[KernelEvents::EXCEPTION]);
    }

    #[Test]
    public function constructorUsesDefaultValues(): void
    {
        $subscriber = new ExceptionSubscriber();

        // Should work without errors using NullLogger default
        $request = new Request([], [], [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->server->set('REQUEST_URI', '/api/test');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsNullForHtmlRequests(): void
    {
        $subscriber = $this->createSubscriber();
        $request = new Request();
        $request->headers->set('Accept', 'text/html');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        // No response set - let Symfony handle it
        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForApiRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/users', 'GET');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForTrackingRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/tracking/save', 'POST');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForInterpretationRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/interpretation/process', 'POST');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForSettingsRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/settings/update', 'POST');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForGetPrefixedRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/getData', 'GET');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForSaveSuffixedRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/entries/save', 'POST');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForDeleteSuffixedRoutes(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/entries/delete', 'DELETE');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForXmlHttpRequest(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/some/route', 'GET');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('Accept', '*/*'); // Override default text/html Accept header

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function onKernelExceptionReturnsJsonForJsonAcceptHeader(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/some/route', 'GET');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    public function handlesJiraApiUnauthorizedException(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/jira/sync', 'POST');
        $request->headers->set('Accept', 'application/json');

        $exception = new JiraApiUnauthorizedException('Token expired', 0, '/oauth/redirect');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame('Jira authentication required', $content['error']);
        $this->assertSame('/oauth/redirect', $content['redirect_url']);
    }

    #[Test]
    public function handlesJiraApiException(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/jira/worklog', 'POST');
        $request->headers->set('Accept', 'application/json');

        $exception = new JiraApiException('Connection failed');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(502, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame('Jira API error', $content['error']);
        $this->assertStringContainsString('Connection failed', $content['message']);
    }

    #[Test]
    #[DataProvider('provideHttpExceptions')]
    public function handlesHttpExceptions(
        Throwable $exception,
        int $expectedStatusCode,
        string $expectedErrorType,
    ): void {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $event = $this->createExceptionEvent($request, $exception);
        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame($expectedStatusCode, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame($expectedErrorType, $content['error']);
    }

    /**
     * @return array<string, array{0: Throwable, 1: int, 2: string}>
     */
    public static function provideHttpExceptions(): array
    {
        return [
            'bad request' => [new BadRequestHttpException('Invalid input'), 400, 'Bad request'],
            'unauthorized' => [new UnauthorizedHttpException('Bearer'), 401, 'Unauthorized'],
            'forbidden' => [new AccessDeniedHttpException('Access denied'), 403, 'Forbidden'],
            'not found' => [new NotFoundHttpException('Resource not found'), 404, 'Not found'],
            'method not allowed' => [new MethodNotAllowedHttpException(['GET', 'POST']), 405, 'Method not allowed'],
            'not acceptable' => [new NotAcceptableHttpException('Format not supported'), 406, 'Not acceptable'],
            'conflict' => [new ConflictHttpException('Resource conflict'), 409, 'Conflict'],
            'unprocessable entity' => [new UnprocessableEntityHttpException('Validation failed'), 422, 'Unprocessable entity'],
            'too many requests' => [new TooManyRequestsHttpException(60), 429, 'Too many requests'],
            'internal server error' => [new HttpException(500, 'Internal error'), 500, 'Internal server error'],
            'bad gateway' => [new HttpException(502, 'Gateway error'), 502, 'Bad gateway'],
            'service unavailable' => [new ServiceUnavailableHttpException(30), 503, 'Service unavailable'],
            'unknown status' => [new HttpException(418, "I'm a teapot"), 418, 'Error'],
        ];
    }

    #[Test]
    public function handlesHttpExceptionWithEmptyMessage(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $exception = new NotFoundHttpException('');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $content = json_decode((string) $response->getContent(), true);

        // Should use default message
        $this->assertSame('The requested resource was not found.', $content['message']);
    }

    #[Test]
    public function handlesGenericExceptionInDevMode(): void
    {
        $subscriber = $this->createSubscriber('dev');
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $exception = new Exception('Something went wrong');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame('Internal server error', $content['error']);
        $this->assertSame('Something went wrong', $content['message']);
        $this->assertSame(Exception::class, $content['exception']);
        $this->assertArrayHasKey('file', $content);
        $this->assertArrayHasKey('line', $content);
        $this->assertArrayHasKey('trace', $content);
    }

    #[Test]
    public function handlesGenericExceptionInProdMode(): void
    {
        $subscriber = $this->createSubscriber('prod');
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $exception = new Exception('Sensitive internal error details');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame('Internal server error', $content['error']);
        // Should hide sensitive details in production
        $this->assertSame('An unexpected error occurred. Please try again later.', $content['message']);
        $this->assertArrayNotHasKey('exception', $content);
        $this->assertArrayNotHasKey('file', $content);
        $this->assertArrayNotHasKey('line', $content);
        $this->assertArrayNotHasKey('trace', $content);
    }

    #[Test]
    public function logsServerErrors(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Server error occurred', $this->callback(
                static fn (array $context) => isset($context['exception']) && $context['exception'] instanceof HttpException,
            ));

        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/test', 'GET');

        $exception = new HttpException(500, 'Internal error');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);
    }

    #[Test]
    public function logsClientErrors(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Client error occurred', $this->callback(
                static fn (array $context) => isset($context['exception']) && $context['exception'] instanceof NotFoundHttpException,
            ));

        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/test', 'GET');

        $exception = new NotFoundHttpException('Not found');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);
    }

    #[Test]
    public function logsUnexpectedExceptions(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Unexpected exception occurred', $this->callback(
                static fn (array $context) => isset($context['exception']) && $context['exception'] instanceof Exception,
            ));

        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/test', 'GET');

        $exception = new Exception('Unexpected error');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);
    }

    #[Test]
    public function htmlPreferenceOverridesApiRouteDetection(): void
    {
        $subscriber = $this->createSubscriber();

        // Even though this is an API route, if Accept explicitly prefers HTML, let Symfony handle it
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'text/html');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        // No response set - let Symfony handle HTML error pages
        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function jsonInAcceptHeaderTakesPrecedenceOverHtml(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/test', 'GET');
        // Both HTML and JSON in Accept header, but JSON should work
        $request->headers->set('Accept', 'application/json, text/html');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    #[Test]
    #[DataProvider('provideDefaultMessages')]
    public function providesDefaultMessagesForStatusCodes(int $statusCode, string $expectedMessage): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $exception = new HttpException($statusCode, '');
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $content = json_decode((string) $response->getContent(), true);

        $this->assertSame($expectedMessage, $content['message']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideDefaultMessages(): array
    {
        return [
            '400' => [400, 'The request was invalid or cannot be processed.'],
            '401' => [401, 'Authentication is required to access this resource.'],
            '403' => [403, 'You do not have permission to access this resource.'],
            '404' => [404, 'The requested resource was not found.'],
            '405' => [405, 'The request method is not allowed for this resource.'],
            '406' => [406, 'The requested format is not acceptable.'],
            '409' => [409, 'The request conflicts with the current state of the resource.'],
            '422' => [422, 'The request was well-formed but contains semantic errors.'],
            '429' => [429, 'Too many requests have been sent in a given amount of time.'],
            '500' => [500, 'An internal server error occurred.'],
            '502' => [502, 'The server received an invalid response from an upstream server.'],
            '503' => [503, 'The service is temporarily unavailable.'],
            '418' => [418, 'An error occurred while processing your request.'], // Unknown code
        ];
    }

    #[Test]
    public function jiraUnauthorizedExceptionWithNullRedirectUrl(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/api/jira/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $exception = new JiraApiUnauthorizedException('Not authenticated', 0, null);
        $event = $this->createExceptionEvent($request, $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $content = json_decode((string) $response->getContent(), true);

        $this->assertNull($content['redirect_url']);
    }

    #[Test]
    public function nonApiRouteWithoutJsonHeaderReturnsNull(): void
    {
        $subscriber = $this->createSubscriber();
        $request = Request::create('/some/html/page', 'GET');

        $event = $this->createExceptionEvent($request, new Exception('Test'));
        $subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }
}
