<?php

namespace Netresearch\TimeTrackerBundle\Helper;


class JiraApiException extends \Exception
{
    /** @var String */
    protected $redirectUrl;

    public function __construct($message, $code, $redirectUrl = null)
    {
        $this->redirectUrl = $redirectUrl;
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
