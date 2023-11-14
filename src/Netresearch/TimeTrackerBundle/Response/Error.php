<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace Netresearch\TimeTrackerBundle\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class Error
 * @package Netresearch\TimeTrackerBundle\Response
 */
class Error extends JsonResponse
{
    /**
     * Error constructor.
     *
     * @param string      $errorMessage
     * @param integer     $statusCode
     * @param string|null $forwardUrl
     */
    public function __construct(
        $errorMessage, $statusCode, $forwardUrl = null, \Throwable $exception = null
    ) {
        $message = ['message' => $errorMessage];

        if ($forwardUrl) {
            $message['forwardUrl'] = $forwardUrl;
        }
        if (ini_get('display_errors')) {
            $message['exception'] = $this->getExceptionAsArray($exception);
        }

        parent::__construct($message, $statusCode);
    }

    protected function getExceptionAsArray(\Throwable $exception = null)
    {
        if ($exception === null) {
            return null;
        }
        return [
            'message' => $exception->getMessage(),
            'code'    => $exception->getCode(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
            'trace'   => $exception->getTrace(),
            'previous' => $this->getExceptionAsArray($exception->getPrevious()),
        ];
    }
}
