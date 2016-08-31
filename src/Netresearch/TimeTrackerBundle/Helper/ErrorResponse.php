<?php
/**
 * Created by PhpStorm.
 * User: tkreissl
 * Date: 02.08.16
 * Time: 13:38
 */

namespace Netresearch\TimeTrackerBundle\Helper;


use Symfony\Component\HttpFoundation\JsonResponse;

class ErrorResponse extends JsonResponse
{
    /**
     * ErrorResponse constructor.
     *
     * @param string      $message
     * @param integer     $statusCode
     * @param string|null $forwardUrl
     */
    public function __construct($message, $statusCode, $forwardUrl = null)
    {
        parent::__construct();
        $values = ['message' => $message];
        if ($forwardUrl) {
            $values['forwardUrl'] = $forwardUrl;
        }
        $this->setContent(json_encode($values));
        $this->setStatusCode($statusCode);
    }
}
