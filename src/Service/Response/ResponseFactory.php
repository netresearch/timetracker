<?php

declare(strict_types=1);

namespace App\Service\Response;

use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Exception;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Factory for creating standardized API responses.
 * Centralizes response creation logic and provides consistent error handling.
 * 
 * @psalm-suppress UnusedClass - Infrastructure class for future API development
 */
class ResponseFactory
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Creates a successful JSON response.
     *
     * @param array<string, mixed> $data
     */
    public function success(array $data = [], ?string $alert = null): JsonResponse
    {
        $responseData = ['success' => true];

        if (!empty($data)) {
            $responseData = array_merge($responseData, $data);
        }

        if (null !== $alert) {
            $responseData['alert'] = $alert;
        }

        return new JsonResponse($responseData);
    }

    /**
     * Creates an error response.
     */
    public function error(
        string $message,
        int $statusCode = HttpResponse::HTTP_BAD_REQUEST,
        ?string $redirectUrl = null,
    ): Error {
        $translatedMessage = $this->translator->trans($message);

        return new Error($translatedMessage, $statusCode, $redirectUrl);
    }

    /**
     * Creates a not found error response.
     */
    public function notFound(string $message = 'Resource not found'): Error
    {
        return $this->error($message, HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Creates an unauthorized error response.
     */
    public function unauthorized(string $message = 'Unauthorized', ?string $redirectUrl = null): Error
    {
        return $this->error($message, HttpResponse::HTTP_UNAUTHORIZED, $redirectUrl);
    }

    /**
     * Creates a forbidden error response.
     */
    public function forbidden(string $message = 'Forbidden', ?string $redirectUrl = null): Error
    {
        return $this->error($message, HttpResponse::HTTP_FORBIDDEN, $redirectUrl);
    }

    /**
     * Creates a validation error response.
     *
     * @param array<string, string> $errors
     */
    public function validationError(array $errors): Error
    {
        $message = $this->translator->trans('Validation failed');

        if (!empty($errors)) {
            $errorMessages = array_map(
                static fn ($field, $error) => sprintf('%s: %s', $field, $error),
                array_keys($errors),
                array_values($errors),
            );
            $message .= ': ' . implode(', ', $errorMessages);
        }

        return $this->error($message, HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Creates a conflict error response.
     */
    public function conflict(string $message = 'Conflict detected'): Error
    {
        return $this->error($message, HttpResponse::HTTP_CONFLICT);
    }

    /**
     * Creates a server error response.
     */
    public function serverError(string $message = 'Internal server error'): Error
    {
        return $this->error($message, HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Creates a response for failed login attempts.
     */
    public function failedLogin(): Error
    {
        return $this->unauthorized('Login failed. Please check your credentials.');
    }

    /**
     * Creates a paginated response.
     *
     * @param list<mixed> $items
     */
    public function paginated(
        array $items,
        int $page,
        int $totalPages,
        int $totalItems,
        int $itemsPerPage,
    ): JsonResponse {
        return $this->success([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $totalItems,
                'itemsPerPage' => $itemsPerPage,
                'hasNext' => $page < $totalPages,
                'hasPrevious' => $page > 1,
            ],
        ]);
    }

    /**
     * Creates a response with additional metadata.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $data, array $metadata): JsonResponse
    {
        return $this->success([
            'data' => $data,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Creates a response for JIRA API errors.
     */
    public function jiraApiError(
        Exception $exception,
        string $fallbackMessage = 'JIRA API error occurred',
    ): Error {
        if ($exception instanceof \App\Exception\Integration\Jira\JiraApiUnauthorizedException) {
            return $this->forbidden($exception->getMessage(), $exception->getRedirectUrl());
        }

        if ($exception instanceof \App\Exception\Integration\Jira\JiraApiException) {
            $message = $exception->getMessage() . '<br />' .
                      $this->translator->trans('Dataset was modified in Timetracker anyway');

            return $this->error($message, HttpResponse::HTTP_BAD_GATEWAY);
        }

        return $this->serverError($fallbackMessage);
    }
}