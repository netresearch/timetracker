<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Holiday;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Util\TimeCalculationService;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\EntryRepository;
use App\Response\Error;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Util\RequestHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Class DefaultController.
 */
class DefaultController extends BaseController
{
    public function __construct(
        private readonly TwigEnvironment $twigEnvironment,
        \Doctrine\Persistence\ManagerRegistry $managerRegistry,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly TimeCalculationService $timeCalculationService,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    // index/getTimeSummary/getSummary/getData now handled by dedicated invokable actions in App\Controller\Default\*

    // getCustomers/getUsers/getCustomer/getProjects now handled by dedicated invokable actions in App\Controller\Default\*

    // getAllProjects/getProjectStructure/getActivities/getHolidays now handled by invokables in App\Controller\Default\*

    // export now handled by App\Controller\Default\ExportCsvAction

    // jiraOAuthCallback now handled by App\Controller\Default\JiraOAuthCallbackAction

    // getTicketTimeSummary now handled by App\Controller\Default\GetTicketTimeSummaryAction

    // getTicketTimeSummaryJs now handled by App\Controller\Default\GetTicketTimeSummaryJsAction
}
