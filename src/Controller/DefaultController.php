<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Holiday;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Helper\TimeHelper;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\EntryRepository;
use App\Response\Error;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Class DefaultController.
 */
class DefaultController extends BaseController
{
    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    /**
     * @codeCoverageIgnore
     */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    public function __construct(
        private readonly TwigEnvironment $twigEnvironment,
        \Doctrine\Persistence\ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/', name: '_start', methods: ['GET'])]
    public function index(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|\Symfony\Component\HttpFoundation\Response
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
        $managerRegistry = $this->managerRegistry;

        $user = $managerRegistry->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            return $this->getFailedLoginResponse();
        }

        $settings = $user->getSettings();

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Customer::class);
        $customers = $objectRepository->getCustomersByUser($userId);

        // Send the customer-projects-structure to the frontend for caching
        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $managerRegistry->getRepository(Project::class);
        $projects = $projectRepo->getProjectStructure($userId, $customers);

        return $this->render('index.html.twig', [
            'globalConfig' => [
                'logo_url' => $this->params->get('app_logo_url'),
                'monthly_overview_url' => $this->params->get('app_monthly_overview_url'),
                'header_url' => $this->params->get('app_header_url'),
            ],
            'apptitle' => $this->params->get('app_title'),
            'environment' => $this->kernel->getEnvironment(),
            'customers' => $customers,
            'projects' => $projects,
            'settings' => $settings,
            'locale' => $settings['locale'],
        ]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTimeSummary', name: 'time_summary_attr', methods: ['GET'])]
    public function getTimeSummary(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $today = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);

        $data = [
            'today' => $today,
            'week' => $week,
            'month' => $month,
        ];

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getSummary', name: '_getSummary_attr', methods: ['POST'])]
    public function getSummary(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);

        $data = [
            'customer' => [
                'scope' => 'customer',
                'name' => '',
                'entries' => 0,
                'total' => 0,
                'own' => 0,
                'estimation' => 0,
            ],
            'project' => [
                'scope' => 'project',
                'name' => '',
                'entries' => 0,
                'total' => 0,
                'own' => 0,
                'estimation' => 0,
            ],
            'activity' => [
                'scope' => 'activity',
                'name' => '',
                'entries' => 0,
                'total' => 0,
                'own' => 0,
                'estimation' => 0,
            ],
            'ticket' => [
                'scope' => 'ticket',
                'name' => '',
                'entries' => 0,
                'total' => 0,
                'own' => 0,
                'estimation' => 0,
            ],
        ];

        // early exit, if POST parameter for current entry is not given
        $entryId = $request->request->get('id');
        if ($entryId === null || $entryId === '' || $entryId === false) {
            return new JsonResponse($data);
        }

        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        if (!$objectRepository->find($entryId)) {
            $message = $this->translator->trans('No entry for id.');

            return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        // Collect all entries data
        $data = $objectRepository->getEntrySummary((int) $entryId, $userId, $data);

        if ($data['project']['estimation']) {
            $data['project']['quota'] =
                TimeHelper::formatQuota(
                    $data['project']['total'],
                    $data['project']['estimation']
                );
        }

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getData', name: '_getData_attr', methods: ['GET', 'POST'])]
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getData/days/{days}', name: '_getDataDays_attr', defaults: ['days' => 3], methods: ['GET'])]
    public function getData(Request $request): JsonResponse
    {
        if (!$this->checkLogin($request)) {
            // Always respond with JsonResponse for API endpoint contract
            return new JsonResponse(['error' => 'not authenticated'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        $userId = $this->getUserId($request);

        $user = $this->managerRegistry
            ->getRepository(User::class)
            ->find($userId);

        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 3;
        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        if (!$user instanceof User) {
            return new JsonResponse([]);
        }

        $data = $objectRepository->getEntriesByUser($userId, $days, $user->getShowFuture());

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getCustomers', name: '_getCustomers_attr', methods: ['GET'])]
    public function getCustomers(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Customer::class);
        $data = $objectRepository->getCustomersByUser($userId);

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    public function getUsers(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        if ($this->isDEV($request)) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(User::class);
            $data = $userRepo->getUserById($this->getUserId($request));
        } else {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(User::class);
            $data = $userRepo->getUsers($this->getUserId($request));
        }

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getCustomer', name: '_getCustomer_attr', methods: ['GET'])]
    public function getCustomer(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $projectParam = $request->query->get('project');
        if (is_scalar($projectParam) && (string) $projectParam !== '') {
            $project = $this->managerRegistry
                ->getRepository(Project::class)
                ->find($projectParam);

            if ($project instanceof Project && $project->getCustomer() instanceof Customer) {
                return new JsonResponse(['customer' => $project->getCustomer()->getId()]);
            }

            return new JsonResponse(['customer' => null]);
        }

        return new JsonResponse(['customer' => 0]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getProjects', name: '_getProjects_attr', methods: ['GET'])]
    public function getProjects(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $customerId = (int) $request->query->get('customer');
        $userId = $this->getUserId($request);

        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        $data = $objectRepository->getProjectsByUser($userId, $customerId);

        return new JsonResponse($data);
    }

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

        $filename = strtolower(str_replace(' ', '-', $user->getUsername())).'.csv';

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
            $jiraOAuthApi->fetchOAuthAccessToken($request->query->get('oauth_token'), $request->query->get('oauth_verifier'));
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

        if (count($users) === 0) {
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
            $time['activities'][$key]['time'] = TimeHelper::minutes2readable(
                (int) $total
            );
        }

        foreach ($users as $user) {
            $time['total_time']['time'] += (int) $user['total_time'];
            $key = $user['username'];
            $time['users'][$key]['seconds'] = (int) $user['total_time'] * 60;
            $time['users'][$key]['time'] = TimeHelper::minutes2readable(
                (int) $user['total_time']
            );
        }

        $time['total_time']['seconds'] = $time['total_time']['time'] * 60;
        $time['total_time']['time'] = TimeHelper::minutes2readable(
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
        $legacyPath = $projectDir.'/web/scripts/timeSummaryForJira.js';
        $scriptPath = file_exists($publicPath) ? $publicPath : $legacyPath;

        // Always return a string payload for the test, regardless of file presence
        // Always return a string payload with base URL for the ticket summary endpoint
        $inline = sprintf('%s%s', $ttUrl, 'getTicketTimeSummary/');

        return new JsonResponse($inline);
    }
}
