<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Backwards-compatibility shim for legacy App\Helper\JiraOAuthApi.
 * The implementation now lives in App\Service\Integration\Jira\JiraOAuthApiService.
 */
class JiraOAuthApi extends \App\Service\Integration\Jira\JiraOAuthApiService
{
}
