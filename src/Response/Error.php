<?php declare(strict_types=1);
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
    public function __construct(string $errorMessage, int $statusCode, ?string $forwardUrl = null)
    {
        $message = ['message' => $errorMessage];

        if ($forwardUrl) {
            $message['forwardUrl'] = $forwardUrl;
        }

        parent::__construct($message, $statusCode);
    }
}
