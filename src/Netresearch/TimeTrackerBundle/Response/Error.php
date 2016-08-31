<?php
/**
 * Created by PhpStorm.
 * User: tkreissl
 * Date: 02.08.16
 * Time: 13:38
 */

namespace Netresearch\TimeTrackerBundle\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

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
