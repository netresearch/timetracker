<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly ?LoggerInterface $logger = null,
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
        $this->logException($throwable, $request->getPathInfo());

        // Determine if we should return JSON response
        $acceptsJson = str_contains((string) $request->headers->get('Accept', ''), 'application/json')
                      || str_contains($request->getPathInfo(), '/api/');

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
                'error' => 'JIRA authentication required',
                'message' => $throwable->getMessage(),
                'redirect_url' => $throwable->getRedirectUrl(),
            ], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        if ($throwable instanceof JiraApiException) {
            return new JsonResponse([
                'error' => 'JIRA API error',
                'message' => $throwable->getMessage(),
            ], \Symfony\Component\HttpFoundation\Response::HTTP_BAD_GATEWAY);
        }

        // Handle HTTP exceptions
        if ($throwable instanceof HttpExceptionInterface) {
            $statusCode = $throwable->getStatusCode();
            $message = $throwable->getMessage() ?: $this->getDefaultMessageForStatusCode($statusCode);

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
            ], \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Production mode - hide internal details
        return new JsonResponse([
            'error' => 'Internal server error',
            'message' => 'An unexpected error occurred. Please try again later.',
        ], \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);
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

    private function logException(Throwable $throwable, string $path): void
    {
        if (!$this->logger instanceof \Psr\Log\LoggerInterface) {
            return;
        }

        $context = [
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'path' => $path,
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ];

        // Determine log level based on exception type
        if ($throwable instanceof HttpExceptionInterface) {
            $statusCode = $throwable->getStatusCode();
            if ($statusCode >= 500) {
                $this->logger->error('Server error occurred', $context);
            } elseif ($statusCode >= 400) {
                $this->logger->warning('Client error occurred', $context);
            }
        } else {
            $this->logger->error('Unexpected exception occurred', $context);
        }
    }
}
