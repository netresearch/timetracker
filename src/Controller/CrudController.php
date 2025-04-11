<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Response\Error;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Helper\JiraOAuthApi;
use App\Helper\TicketHelper;
use App\Model\JsonResponse;
use App\Model\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @deprecated This controller is being refactored into separate controllers with more specific responsibilities.
 * See TimeEntryController for time entry operations.
 */
class CrudController extends BaseController
{
    private \Psr\Log\LoggerInterface $logger;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setLogger(LoggerInterface $trackingLogger): void
    {
        $this->logger = $trackingLogger;
    }

    /**
     * Log data to the logger.
     *
     * @param array $data
     * @param bool $raw
     */
    private function logData(array $data, bool $raw = false): void
    {
        if (!$this->logger) {
            return;
        }

        $this->logger->info(print_r($data, $raw));
    }
}
