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

    public function __construct($message, $statuscode, $forwardUrl = null)
    {
        parent::__construct();
        $values = ['message' => $message];
        if ($forwardUrl) {
            $values['forwardUrl'] = $forwardUrl;
        }
        $this->setContent(json_encode($values));
        $this->setStatusCode($statuscode);
    }
}