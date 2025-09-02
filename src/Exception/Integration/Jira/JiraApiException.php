<?php

declare(strict_types=1);

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Exception\Integration\Jira;

use Exception;
use Throwable;

/**
 * Class JiraApiException.
 */
class JiraApiException extends Exception
{
    /**
     * JiraApiException constructor.
     */
    public function __construct(
        string $message,
        int $code = 0,
        protected ?string $redirectUrl = null,
        ?Throwable $throwable = null,
    ) {
        if (!str_starts_with($message, 'Jira:')) {
            $message = 'Jira: ' . $message;
        }

        parent::__construct($message, $code, $throwable);
    }

    /**
     * Get the URL to redirect to for authentication if needed.
     */
    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }
}
