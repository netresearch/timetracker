<?php

namespace App\Helper;

/**
 * The user needs to authorize in Jira first and get an OAuth token
 */
class JiraApiUnauthorizedException extends JiraApiException
{
}
