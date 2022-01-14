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
    public function __construct($message, $code, protected $redirectUrl = null)
    {
        $message = 'JiraApi: '.$message;
        parent::__construct($message, $code, null);
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }
}
