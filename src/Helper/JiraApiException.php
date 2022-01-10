<?php declare(strict_types=1);
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Helper;

use Exception;

/**
 * Class JiraApiException.
 */
class JiraApiException extends Exception
{
    /**
     * JiraApiException constructor.
     *
     * @param $message
     * @param $code
     * @param null $redirectUrl
     */
    public function __construct($message, $code, protected $redirectUrl = null)
    {
        $message = 'JiraApi: '.$message;
        parent::__construct($message, $code, null);
    }

    /**
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->redirectUrl;
    }
}
