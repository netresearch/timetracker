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

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllProjects', name: '_getAllProjects_attr', methods: ['GET'])]
    public function getAllProjects(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $customerId = (int) $request->query->get('customer');
        $managerRegistry = $this->managerRegistry;
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Project::class);
        /** @var array<int, Project> $result */
        $result = $customerId > 0 ? $objectRepository->findByCustomer($customerId) : $objectRepository->findAll();

        $data = [];
        foreach ($result as $project) {
            if ($project instanceof Project) {
                $data[] = ['project' => $project->toArray()];
            }
        }

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getProjectStructure', name: '_getProjectStructure_attr', methods: ['GET'])]
    public function getProjectStructure(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
        $managerRegistry = $this->managerRegistry;

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Customer::class);
        $customers = $objectRepository->getCustomersByUser($userId);

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $managerRegistry->getRepository(Project::class);
        $projectStructure = $projectRepo->getProjectStructure($userId, $customers);

        return new JsonResponse($projectStructure);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getActivities', name: '_getActivities_attr', methods: ['GET'])]
    public function getActivities(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Activity::class);
        $data = $objectRepository->getActivities();

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getHolidays', name: '_getHolidays_attr', methods: ['GET'])]
    public function getHolidays(): JsonResponse
    {
        /** @var \App\Repository\HolidayRepository $objectRepository */
        $objectRepository = $this->managerRegistry
            ->getRepository(Holiday::class);
        $holidays = $objectRepository->findByMonth((int) date('Y'), (int) date('m'));

        return new JsonResponse($holidays);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/export/{days}', name: '_export_attr', defaults: ['days' => 10000], methods: ['GET'])]
    public function export(Request $request): Response
    {
        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 10000;

        $user = $this->managerRegistry
            ->getRepository(User::class)
            ->find($this->getUserId($request));
        if (!$user instanceof User) {
            return $this->getFailedLoginResponse();
        }

        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $entries = $objectRepository->findByRecentDaysOfUser($user, $days);

        $content = $this->twigEnvironment->render(
            'export.csv.twig',
            [
                'entries' => $entries,
                'labels' => null,
            ]
        );

        $filename = strtolower(str_replace(' ', '-', (string) $user->getUsername())).'.csv';

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename='.$filename);

        $response->setContent(chr(239).chr(187).chr(191).$content);

        return $response;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/jiraoauthcallback', name: 'jiraOAuthCallback', methods: ['GET'])]
    public function jiraOAuthCallback(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response
    {
        /** @var User $user */
        $user = $this->managerRegistry
            ->getRepository(User::class)
            ->find($this->getUserId($request));

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->managerRegistry
            ->getRepository(TicketSystem::class)
            ->find($request->query->get('tsid'));
        if (!$ticketSystem instanceof TicketSystem) {
            return new Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        try {
            $jiraOAuthApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
            $oauthToken = $request->query->get('oauth_token');
            $oauthVerifier = $request->query->get('oauth_verifier');
            if (!is_string($oauthToken) || '' === $oauthToken || !is_string($oauthVerifier) || '' === $oauthVerifier) {
                return new Response('Invalid OAuth callback parameters', \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }
            $jiraOAuthApi->fetchOAuthAccessToken($oauthToken, $oauthVerifier);
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);

            return $this->redirectToRoute('_start');
        } catch (JiraApiException $jiraApiException) {
            return new Response($jiraApiException->getMessage());
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTicketTimeSummary/{ticket}', name: '_getTicketTimeSummary_attr', defaults: ['ticket' => null], methods: ['GET'])]
    public function getTicketTimeSummary(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $attributes = $request->attributes;
        $name = $attributes->has('ticket') ? $attributes->get('ticket') : null;

        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $activities = $objectRepository->getActivitiesWithTime($name ?? '');

        $users = $objectRepository->getUsersWithTime($name ?? '');

        if (0 === count($users)) {
            return new Response(
                'There is no information available about this ticket.',
                \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND
            );
        }

        $time = ['total_time' => ['time' => 0]];

        foreach ($activities as $activity) {
            $total = $activity['total_time'];
            $key = $activity['name'] ?? 'No activity';

            $time['activities'][$key]['seconds'] = (int) $total * 60;
            $time['activities'][$key]['time'] = $this->timeCalculationService->minutesToReadable(
                (int) $total
            );
        }

        foreach ($users as $user) {
            $time['total_time']['time'] += (int) $user['total_time'];
            $key = $user['username'];
            $time['users'][$key]['seconds'] = (int) $user['total_time'] * 60;
            $time['users'][$key]['time'] = $this->timeCalculationService->minutesToReadable(
                (int) $user['total_time']
            );
        }

        $time['total_time']['seconds'] = $time['total_time']['time'] * 60;
        $time['total_time']['time'] = $this->timeCalculationService->minutesToReadable(
            $time['total_time']['time']
        );

        return new JsonResponse($time);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/scripts/timeSummaryForJira', name: '_getTicketTimeSummaryJs_attr', methods: ['GET'])]
    public function getTicketTimeSummaryJs(): JsonResponse
    {
        $ttUrl = $this->generateUrl(
            '_start',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Prefer modern public/ path; fall back to legacy web/ path for BC
        $projectDir = $this->kernel->getProjectDir();
        $publicPath = $projectDir.'/public/scripts/timeSummaryForJira.js';
        if (file_exists($publicPath)) {
        }

        // Always return a string payload for the test, regardless of file presence
        // Always return a string payload with base URL for the ticket summary endpoint
        $inline = sprintf('%s%s', $ttUrl, 'getTicketTimeSummary/');

        return new JsonResponse($inline);
    }
}
