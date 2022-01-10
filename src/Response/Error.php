<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class Error
 * @package App\Response
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
    public function __construct($errorMessage, $statusCode, $forwardUrl = null)
    {
        $message = ['message' => $errorMessage];

        if ($forwardUrl) {
            $message['forwardUrl'] = $forwardUrl;
        }

        parent::__construct($message, $statusCode);
    }
}
