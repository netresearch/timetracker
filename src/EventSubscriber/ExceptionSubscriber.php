<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Global exception handler for converting exceptions to appropriate responses.
 */
class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $environment = 'prod',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $exceptionEvent): void
    {
        $throwable = $exceptionEvent->getThrowable();
        $request = $exceptionEvent->getRequest();

        // Log the exception
        $this->logException($throwable);

        // Determine if we should return JSON response
        // Include API-like routes used by ExtJS frontend
        $pathInfo = $request->getPathInfo();
        $acceptsJson = str_contains((string) $request->headers->get('Accept', ''), 'application/json')
                      || 'XMLHttpRequest' === $request->headers->get('X-Requested-With')
                      || str_contains($pathInfo, '/api/')
                      || str_contains($pathInfo, '/tracking/')
                      || str_contains($pathInfo, '/interpretation/')
                      || str_contains($pathInfo, '/settings/')
                      || str_starts_with($pathInfo, '/get')
                      || str_ends_with($pathInfo, '/save')
                      || str_ends_with($pathInfo, '/delete');

        if (!$acceptsJson) {
            // Let Symfony handle HTML error pages
            return;
        }

        // Convert exception to appropriate response
        $jsonResponse = $this->createResponseFromException($throwable);
        $exceptionEvent->setResponse($jsonResponse);
    }

    private function createResponseFromException(Throwable $throwable): JsonResponse
    {
        // Handle JIRA API exceptions
        if ($throwable instanceof JiraApiUnauthorizedException) {
            return new JsonResponse([
                'error' => 'Jira authentication required',
                'message' => $throwable->getMessage(),
                'redirect_url' => $throwable->getRedirectUrl(),
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($throwable instanceof JiraApiException) {
            return new JsonResponse([
                'error' => 'Jira API error',
                'message' => $throwable->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Handle HTTP exceptions
        if ($throwable instanceof HttpExceptionInterface) {
            $statusCode = $throwable->getStatusCode();
            $message = '' !== $throwable->getMessage() ? $throwable->getMessage() : $this->getDefaultMessageForStatusCode($statusCode);

            return new JsonResponse([
                'error' => $this->getErrorTypeForStatusCode($statusCode),
                'message' => $message,
            ], $statusCode);
        }

        // Handle generic exceptions (only show details in dev mode)
        if ('dev' === $this->environment) {
            return new JsonResponse([
                'error' => 'Internal server error',
                'message' => $throwable->getMessage(),
                'exception' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => $throwable->getTraceAsString(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Production mode - hide internal details
        return new JsonResponse([
            'error' => 'Internal server error',
            'message' => 'An unexpected error occurred. Please try again later.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function getErrorTypeForStatusCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            406 => 'Not acceptable',
            409 => 'Conflict',
            422 => 'Unprocessable entity',
            429 => 'Too many requests',
            500 => 'Internal server error',
            502 => 'Bad gateway',
            503 => 'Service unavailable',
            default => 'Error',
        };
    }

    private function getDefaultMessageForStatusCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'The request was invalid or cannot be processed.',
            401 => 'Authentication is required to access this resource.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The requested resource was not found.',
            405 => 'The request method is not allowed for this resource.',
            406 => 'The requested format is not acceptable.',
            409 => 'The request conflicts with the current state of the resource.',
            422 => 'The request was well-formed but contains semantic errors.',
            429 => 'Too many requests have been sent in a given amount of time.',
            500 => 'An internal server error occurred.',
            502 => 'The server received an invalid response from an upstream server.',
            503 => 'The service is temporarily unavailable.',
            default => 'An error occurred while processing your request.',
        };
    }

    private function logException(Throwable $throwable): void
    {
        // PSR-3 compliant: static message with exception in context
        // Path info is available in exception stack trace
        if ($throwable instanceof HttpExceptionInterface) {
            $statusCode = $throwable->getStatusCode();
            if ($statusCode >= 500) {
                $this->logger->error('Server error occurred', ['exception' => $throwable]);
            } elseif ($statusCode >= 400) {
                $this->logger->warning('Client error occurred', ['exception' => $throwable]);
            }
        } else {
            $this->logger->error('Unexpected exception occurred', ['exception' => $throwable]);
        }
    }
}
