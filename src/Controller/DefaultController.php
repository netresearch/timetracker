<?php

namespace App\Controller;

use App\Response\Error;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Entity\TicketSystem;
use App\Helper\JiraApiException;
use App\Helper\JiraOAuthApi;
use App\Helper\LdapClient;
use App\Helper\TimeHelper;
use App\Repository\EntryRepository;
use App\Entity\User;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\Holiday;

use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Class DefaultController
 * @package App\Controller
 */
class DefaultController extends BaseController
{
    public function __construct(
        private readonly TwigEnvironment $twigEnvironment,
    )
    {
    }

    /**
     * @throws \ReflectionException
     */
    public function indexAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\Symfony\Component\HttpFoundation\Response
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = (int) $this->getUserId($request);
        $managerRegistry = $this->getDoctrine();

        $user = $managerRegistry->getRepository(User::class)->find($userId);
        $settings = $user->getSettings();

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Customer::class);
        $customers = $objectRepository->getCustomersByUser($userId);

        // Send the customer-projects-structure to the frontend for caching
        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $managerRegistry->getRepository(Project::class);
        $projects = $projectRepo->getProjectStructure($userId, $customers);

        return $this->render('index.html.twig', [
            'globalConfig'  => [
                'logo_url'              => $this->params->get('app_logo_url'),
                'monthly_overview_url'  => $this->params->get('app_monthly_overview_url'),
                'header_url'            => $this->params->get('app_header_url'),
            ],
            'apptitle'      => $this->params->get('app_title'),
            'environment'   => $this->kernel->getEnvironment(),
            'customers'     => $customers,
            'projects'      => $projects,
            'settings'      => $settings,
            'locale'        => $settings['locale']
        ]);
    }

    /**
     * @return Response|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function loginAction(Request $request): \App\Model\Response
    {
        // Force rendering the template without any redirects
        $response = new Response();
        $content = $this->renderView('login.html.twig', [
            'locale'  => 'en',
            'apptitle' => $this->params->get('app_title'),
            'last_username' => $request->getSession()->get('_security.last_username'),
            'error' => null,
        ]);
        $response->setContent($content);
        return $response;
    }

    /**
     * @return Response|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function logoutAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        // This method should no longer be called directly since Symfony's security component handles the logout
        // Redirect to the logout route which is handled by Symfony's security logout handler
        return $this->redirectToRoute('_logout');
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getTimeSummaryAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = (int) $this->getUserId($request);
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Entry::class);
        $today = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);

        $data = [
            'today' => $today,
            'week'  => $week,
            'month' => $month,
        ];

        return new JsonResponse($data);
    }

    /**
     * Retrieves a summary of an entry (project total/own, ticket total/own)
     *
     * @return Response|\Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getSummaryAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse|\App\Response\Error
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = (int) $this->getUserId($request);

        $data = [
            'customer' => [
                'scope'      => 'customer',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ],
            'project' => [
                'scope'      => 'project',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ],
            'activity' => [
                'scope'      => 'activity',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ],
            'ticket' => [
                'scope'      => 'ticket',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ],
        ];

        // early exit, if POST parameter for current entry is not given
        $entryId = $request->request->get('id');
        if (!$entryId) {
            return new JsonResponse($data);
        }

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Entry::class);
        if (!$objectRepository->find($entryId)) {
            $message = $this->translator->trans('No entry for id.');
            return new Error($message, 404);
        }

        // Collect all entries data
        $data = $objectRepository->getEntrySummary($entryId, $userId, $data);

        if ($data['project']['estimation']) {
            $data['project']['quota'] =
                TimeHelper::formatQuota(
                    $data['project']['total'],
                    $data['project']['estimation']);
        }

        return new JsonResponse($data);
    }


    /**
     * Retrieves all current entries of the user logged in.
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getDataAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = (int) $this->getUserId($request);

        $user = $this->getDoctrine()
            ->getRepository(User::class)
            ->find($userId);

        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 3;
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Entry::class);
        $data = $objectRepository->getEntriesByUser($userId, $days, $user->getShowFuture());

        return new JsonResponse($data);
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    public function getCustomersAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = (int) $this->getUserId($request);
        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Customer::class);
        $data = $objectRepository->getCustomersByUser($userId);

        return new JsonResponse($data);
    }

    /**
     * Developers may see their own data only, CTL and PL may see everyone.
     * Used in Interpretation tab to get all users
     *
     * @return Response
     */
    public function getUsersAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        if ($this->isDEV($request)) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->getDoctrine()->getRepository(User::class);
            $data = $userRepo->getUserById($this->getUserId($request));
        } else {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->getDoctrine()->getRepository(User::class);
            $data = $userRepo->getUsers($this->getUserId($request));
        }

        return new JsonResponse($data);
    }

    /**
     * @return Response
     */
    public function getCustomerAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        if ($request->get('project')) {
            $project = $this->getDoctrine()
                ->getRepository(Project::class)
                ->find($request->get('project'));

            return new JsonResponse(['customer' => $project->getCustomer()->getId()]);
        }

        return new JsonResponse(['customer' => 0]);
    }

    /**
     * @return Response
     * @throws \ReflectionException
     */
    public function getProjectsAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $customerId = (int) $request->query->get('customer');
        $userId = (int) $this->getUserId($request);

        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Project::class);
        $data = $objectRepository->getProjectsByUser($userId, $customerId);

        return new JsonResponse($data);
    }

    /**
     * @return Response
     * @throws \Doctrine\DBAL\DBALException
     * @throws \ReflectionException
     */
    public function getAllProjectsAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $customerId = (int) $request->query->get('customer');
        $managerRegistry = $this->getDoctrine();
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Project::class);
        $result = $customerId > 0 ? $objectRepository->findByCustomer($customerId) : $objectRepository->findAll();

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new JsonResponse($data);
    }

    /**
     * Return projects grouped by customer ID.
     *
     * Needed for frontend tracking autocompletion.
     */
    public function getProjectStructureAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = (int) $this->getUserId($request);
        $managerRegistry = $this->getDoctrine();

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Customer::class);
        $customers = $objectRepository->getCustomersByUser($userId);

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $managerRegistry->getRepository(Project::class);
        $projectStructure = $projectRepo->getProjectStructure($userId, $customers);

        return new JsonResponse($projectStructure);
    }

    /**
     * @return Response
     */
    public function getActivitiesAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Activity::class);
        $data = $objectRepository->getActivities();
        return new JsonResponse($data);
    }

    /**
     * @return Response
     */
    public function getHolidaysAction()
    {
        /** @var \App\Repository\HolidayRepository $objectRepository */
        $objectRepository = $this->getDoctrine()
            ->getRepository(Holiday::class);
        $holidays = $objectRepository->findByMonth(date("Y"), date("m"));
        return new JsonResponse($holidays);
    }

    /**
     * @throws \Twig\Error\Error
     * @throws \Exception
     */
    public function exportAction(Request $request): \App\Model\Response
    {
        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 10000;

        $user = $this->getDoctrine()
            ->getRepository(User::class)
            ->find($this->getUserId($request));

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Entry::class);
        $entries = $objectRepository->findByRecentDaysOfUser($user, $days);

        $content = $this->twigEnvironment->render(
            'export.csv.twig',
            [
                'entries' => $entries,
                'labels'  => null,
            ]
        );

        $filename = strtolower(str_replace(' ', '-', $user->getUsername())) . '.csv';

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename);
        $response->setContent(chr(239) . chr(187) . chr(191) . $content);

        return $response;
    }

    /**
     * Handles returning user from OAuth service.
     *
     * User is redirected to app after accepting or declining granting access for this app.
     */
    public function jiraOAuthCallbackAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response
    {
        /** @var User $user */
        $user = $this->getDoctrine()
            ->getRepository(User::class)
            ->find($this->getUserId($request));

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->getDoctrine()
            ->getRepository(TicketSystem::class)
            ->find($request->get('tsid'));

        try {
            $jiraOAuthApi = new JiraOAuthApi(
                $user, $ticketSystem, $this->getDoctrine(), $this->router
            );
            $jiraOAuthApi->fetchOAuthAccessToken($request->get('oauth_token'), $request->get('oauth_verifier'));
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);
            return $this->redirectToRoute('_start');
        } catch (JiraApiException $jiraApiException) {
            return new Response($jiraApiException->getMessage());
        }
    }

    /**
     * Get a list of information (activities, times, users) about a ticket for time evaluation
     *
     * @param Request $request Incoming HTTP request
     *
     * @return object JSON data with time information about activities, total time and users
     */
    public function getTicketTimeSummaryAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $attributes = $request->attributes;
        $name = $attributes->has('ticket') ? $attributes->get('ticket') : null;

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->getDoctrine()->getRepository(Entry::class);
        $activities = $objectRepository->getActivitiesWithTime($name);

        $users = $objectRepository->getUsersWithTime($name);

        if (is_null($name) || empty($users)) {
            return new Response(
                'There is no information available about this ticket.', 404
            );
        }

        $time['total_time']['time'] = 0;

        foreach ($activities as $activity) {

            $total = $activity['total_time'];
            $key = $activity['name'] ?? 'No activity';

            $time['activities'][$key]['seconds'] = (int) $total * 60;
            $time['activities'][$key]['time'] = TimeHelper::minutes2readable(
                $total
            );
        }

        foreach ($users as $user) {
            $time['total_time']['time'] += (int) $user['total_time'];
            $key = $user['username'];
            $time['users'][$key]['seconds'] = (int) $user['total_time'] * 60;
            $time['users'][$key]['time'] = TimeHelper::minutes2readable(
                $user['total_time']
            );
        }

        $time['total_time']['seconds'] = $time['total_time']['time'] * 60;
        $time['total_time']['time'] = TimeHelper::minutes2readable(
            $time['total_time']['time']
        );

        return new JsonResponse($time);
    }

    /**
     * Return the jira cloud ticket summary javascript with a correct TT URL.
     *
     * @return Response
     */
    public function getTicketTimeSummaryJsAction()
    {
        $ttUrl = $this->generateUrl(
            '_start', [], UrlGeneratorInterface::ABSOLUTE_URL
        );

        $content = file_get_contents(
            $this->params->get('kernel.root_dir')
            . '/../web/scripts/timeSummaryForJira.js'
        );
        $content = str_replace('https://timetracker/', $ttUrl, $content);

        return new JsonResponse($content);
    }
}
