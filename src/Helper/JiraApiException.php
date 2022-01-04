<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

use Exception;
/**
 * Class JiraApiException
 * @package App\Helper
 */
class JiraApiException extends Exception
{
    /**
     * JiraApiException constructor.
     * @param $message
     * @param $code
     * @param null $redirectUrl
     */
    public function __construct($message, $code, protected $redirectUrl = null)
    {
        $message = 'JiraApi: '. $message;
        parent::__construct($message, $code, null);
    }

    /**
     * @return String
     */
    public function getRedirectUrl()
    {
        return $this->redirectUrl;
    }
}
