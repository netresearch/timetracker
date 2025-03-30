<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

/**
 * Class JiraApiException
 * @package App\Helper
 */
class JiraApiException extends \Exception
{
    /**
     * JiraApiException constructor.
     * @param $message
     * @param $code
     */
    public function __construct($message, $code, protected $redirectUrl = null, \Throwable $throwable = null)
    {
        if (!str_starts_with((string) $message, 'Jira:')) {
            $message = 'Jira: '. $message;
        }

        parent::__construct($message, $code, $throwable);
    }

    /**
     * @return String
     */
    public function getRedirectUrl()
    {
        return $this->redirectUrl;
    }
}
