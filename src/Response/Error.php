<?php

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class Error.
 */
class Error extends JsonResponse
{
    /**
     * Error constructor.
     *
     * @param string      $errorMessage
     * @param int         $statusCode
     * @param string|null $forwardUrl
     */
    public function __construct(
        $errorMessage,
        $statusCode,
        $forwardUrl = null,
        ?\Throwable $throwable = null,
    ) {
        $message = ['message' => $errorMessage];

        if ($forwardUrl) {
            $message['forwardUrl'] = $forwardUrl;
        }

        if (ini_get('display_errors')) {
            $message['exception'] = $this->getExceptionAsArray($throwable);
        }

        parent::__construct($message, $statusCode > 0 ? $statusCode : 400);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getExceptionAsArray(?\Throwable $throwable = null): ?array
    {
        if (!$throwable instanceof \Throwable) {
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
