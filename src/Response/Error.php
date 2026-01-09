<?php

declare(strict_types=1);

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Response;

use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

use function ini_get;
use function is_string;

/**
 * Class Error.
 */
class Error extends JsonResponse
{
    /**
     * Error constructor.
     */
    public function __construct(
        string $errorMessage,
        int $statusCode,
        ?string $forwardUrl = null,
        ?Throwable $throwable = null,
    ) {
        $message = ['message' => $errorMessage];

        if (is_string($forwardUrl) && '' !== $forwardUrl) {
            $message['forwardUrl'] = $forwardUrl;
        }

        if (false !== ini_get('display_errors') && '' !== ini_get('display_errors')) {
            $message['exception'] = $this->getExceptionAsArray($throwable);
        }

        // Ensure valid status code
        $validStatusCode = $statusCode > 0 ? $statusCode : 400;

        // Initialize parent JsonResponse with proper data to resolve PropertyNotSetInConstructor
        parent::__construct($message, $validStatusCode, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * @return array{
     *   message: string,
     *   class: class-string<Throwable>,
     *   code: int|string,
     *   file: string,
     *   line: int,
     *   trace: list<array{function: string, line?: int, file?: string, class?: class-string, type?: '->'|'::', args?: array<mixed>, object?: object}>,
     *   previous: array<string, mixed>|null
     * }|null
     */
    protected function getExceptionAsArray(?Throwable $throwable = null): ?array
    {
        if (! $throwable instanceof Throwable) {
            return null;
        }

        return [
            'message' => $throwable->getMessage(),
            'class' => $throwable::class,
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTrace(),
            'previous' => $this->getExceptionAsArray($throwable->getPrevious()),
        ];
    }
}
