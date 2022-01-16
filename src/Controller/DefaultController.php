<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\TicketSystem;
use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Exception;
use Twig\Error\Error;
use App\Helper\JiraApiException;
use App\Helper\TimeHelper;
use App\Repository\EntryRepository;
use App\Entity\User\Types;
use App\Kernel;
use App\Model\Response;
use App\Services\JiraOAuthApi;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class DefaultController.
 */
class DefaultController extends BaseController
{
    #[Route(path: '/', name: '_start')]
    public function indexAction(Kernel $kernel): Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $user = $this->getWorkUser();
        $settings = $user?->getSettings();

        if ($user) {
            // Send customers to the frontend for caching
            $customers = $this->customerRepo->getCustomersByUser($user->getId())
            ;
            // Send the customer-projects-structure to the frontend for caching
            $projects    = $this->projectRepo->getProjectStructure($user->getId(), $customers);
        }

        // these settings are used to render frontend according to user settings and permissions
        // this should also work for users not loaded rom ldap_user_provider or db
        // that's why we need to override 'type' for user accordingly to permission level
        if ($this->isGranted('ROLE_PL')) {
            $settings['role'] = 'ROLE_PL';
            $settings['type'] = Types::PL;
        } elseif ($this->isGranted('ROLE_CTL')) {
            $settings['role'] = 'ROLE_CTL';
            $settings['type'] = Types::CTL;
        } else {
            $settings['role'] = 'ROLE_DEV';
            $settings['type'] = Types::DEV;
        }

        return $this->render('index.html.twig', [
            'globalConfig' => [
                'logo_url'             => $this->params->get('app.logo_url'),
                'monthly_overview_url' => $this->params->get('app.monthly_overview_url'),
                'header_url'           => $this->params->get('app.header_url'),
            ],
            'environment' => $kernel->getEnvironment(),
            'customers'   => $customers ?? [],
            'projects'    => $projects ?? [],
            'settings'    => $settings ?? [],
            'locale'      => $settings['locale'] ?? 'en',
        ]);
    }

    #[Route(path: '/getTimeSummary', name: 'time_summary')]
    public function getTimeSummaryAction(): Response|RedirectResponse
    {
        $userId = (int) $this->getUserId();
        $today  = $this->entryRepo->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week   = $this->entryRepo->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month  = $this->entryRepo->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);
        $data   = [
            'today' => $today,
            'week'  => $week,
            'month' => $month,
        ];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Retrieves a summary of an entry (project total/own, ticket total/own).
     */
    #[Route(path: '/getSummary', name: '_getSummary')]
    public function getSummaryAction(): Response|RedirectResponse
    {
        $userId = (int) $this->getUserId();
        $data   = [
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
        $entryId = $this->request->get('id');
        if (!$entryId) {
            return new Response(json_encode($data));
        }
        // Collect all entries data
        $data = $this->entryRepo->getEntrySummary($entryId, $userId, $data);
        if ($data['project']['estimation']) {
            $data['project']['quota'] =
                TimeHelper::formatQuota(
                    $data['project']['total'],
                    $data['project']['estimation']
                );
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Retrieves all current entries of the user logged in.
     */
    #[Route(path: '/getData/days/{days}', name: '_getDataDays')]
    #[Route(path: '/getData', name: '_getData')]
    public function getDataAction(int $days = 3): Response|RedirectResponse
    {
        $result = [];
        $user   = $this->getWorkUser();
        //$days = $this->request->attributes->has('days') ? (int) $this->request->attributes->get('days') : 3;
        $data   = $this->entryRepo->getEntriesByUser($user->getId(), $days, $user->getShowFuture());

        // BC - convert object into array
        foreach ($data as $entry) {
            $result[] = ['entry' => $entry->toArray()];
        }

        return new Response(json_encode($result, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getCustomers', name: '_getCustomers')]
    public function getCustomersAction(): Response|RedirectResponse
    {
        $data = $this->customerRepo->getCustomersByUser($this->getUserId());

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Developers may see their own data only, ROLE_ADMIN (CTL and PL) may see everyone.
     */
    #[Route(path: '/getUsers', name: '_getUsers')]
    public function getUsersAction(): Response
    {
        $data   = [];
        $userId = $this->getUserId();

        if (null === $userId) {
            return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            // return all users for admins
            $data = $this->userRepo->getUsers($userId);
        } else {
            $data = $this->userRepo->getUserById($userId);
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getCustomer', name: '_getCustomer')]
    public function getCustomerAction(): Response
    {
        if ($this->request->get('project')) {
            $project = $this->projectRepo->find($this->request->get('project'));

            return new Response(json_encode(['customer' => $project->getCustomer()->getId()], \JSON_THROW_ON_ERROR));
        }

        return new Response(json_encode(['customer' => 0]));
    }

    #[Route(path: '/getProjects', name: '_getProjects')]
    public function getProjectsAction(): Response
    {
        $customerId = (int) $this->request->query->get('customer');
        $userId     = (int) $this->getUserId();
        $data       = $this->projectRepo->getProjectsByUser($userId, $customerId);

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllProjects', name: '_getAllProjects')]
    public function getAllProjectsAction(): Response
    {
        $customerId = (int) $this->request->query->get('customer');
        if ($customerId > 0) {
            $result = $this->projectRepo->findByCustomer($customerId);
        } else {
            $result = $this->projectRepo->findAll();
        }
        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getActivities', name: '_getActivities')]
    public function getActivitiesAction(): Response
    {
        return new Response(json_encode($this->activityRepo->getActivities(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getHolidays', name: '_getHolidays')]
    public function getHolidaysAction(): Response
    {
        $holidays = $this->holidayRepo->findByMonth((int) date('Y'), (int) date('m'));

        return new Response(json_encode($holidays, \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws Error
     * @throws Exception
     */
    #[Route(path: '/export/{days}', name: '_export')]
    public function exportAction(int $days = 10000): Response
    {
        //$days = $this->request->attributes->has('days') ? (int) $this->request->attributes->get('days') : 10000;

        $entries = $this->entryRepo->findByRecentDaysOfUser($this->getWorkUser(), $days);
        $content = $this->render(
            'export.csv.twig',
            [
                'entries' => $entries,
                'labels'  => null,
            ]
        );
        $filename = strtolower(str_replace(' ', '-', $this->getWorkUser()->getUsername())).'.csv';
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename='.$filename);
        $response->setContent(\chr(239).\chr(187).\chr(191).$content);

        return $response;
    }

    protected function getJiraOAuthApi(User $user, TicketSystem $ticketSystem): JiraOAuthApi
    {
        return $this->container->get('JiraOAuthApi')
            ->setUser($user)
            ->setTicketSystem($ticketSystem);
    }

    /**
     * Handles returning user from OAuth service.
     *
     * User is redirected to app after accepting or declining granting access for this app.
     */
    #[Route(path: '/jiraoauthcallback')]
    public function jiraOAuthCallbackAction(): Response|RedirectResponse
    {
        $user         = $this->userRepo->find($this->getUserId());
        $ticketSystem = $this->ticketSystemRepo->find($this->request->get('tsid'));

        try {
            $jiraOAuthApi = $this->getJiraOAuthApi($user, $ticketSystem);
            $jiraOAuthApi->fetchOAuthAccessToken($this->request->get('oauth_token'), $this->request->get('oauth_verifier'));
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);

            return $this->redirectToRoute('_start');
        } catch (JiraApiException $e) {
            return new Response($e->getMessage());
        }
    }

    /**
     * Get a list of information (activities, times, users) about a ticket for time evaluation.
     *
     * @return object JSON data with time information about activities, total time and users
     */
    #[Route(path: '/getTicketSummary/{ticket}', name: '_getTicketTimeSummary')]
    public function getTicketTimeSummaryAction(string $ticket = null)
    {
        $time = [];
        
        //$attributes = $this->request->attributes;
        //$ticket = $attributes->has('ticket') ? $attributes->get('ticket') : null;
        $activities = $this->entryRepo->getActivitiesWithTime($ticket);
        $users = $this->entryRepo->getUsersWithTime($ticket);
        if (null === $ticket || empty($users)) {
            return new Response(
                'There is no information available about this ticket.',
                404
            );
        }
        $time['total_time']['time'] = 0;
        foreach ($activities as $activity) {
            $total = $activity['total_time'];
            $key   = $activity['name'] ?? 'No activity';

            $time['activities'][$key]['seconds'] = (int) $total * 60;
            $time['activities'][$key]['time']    = TimeHelper::minutes2readable(
                $total
            );
        }
        foreach ($users as $user) {
            $time['total_time']['time'] += (int) $user['total_time'];
            $key                            = $user['username'];
            $time['users'][$key]['seconds'] = (int) $user['total_time'] * 60;
            $time['users'][$key]['time']    = TimeHelper::minutes2readable(
                $user['total_time']
            );
        }
        $time['total_time']['seconds'] = (int) $time['total_time']['time'] * 60;
        $time['total_time']['time']    = TimeHelper::minutes2readable(
            $time['total_time']['time']
        );

        return new Response(
            json_encode($time, \JSON_THROW_ON_ERROR),
            200,
            ['Content-type' => 'application/json']
        );
    }

    /**
     * Return the jira cloud ticket summary javascript with a correct TT URL.
     *
     * @return Response
     */
    #[Route(path: '/getTicketSummaryForJira', name: '_getTicketTimeSummaryJs')]
    public function getTicketTimeSummaryJsAction()
    {
        $ttUrl = $this->generateUrl(
            '_start',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $content = file_get_contents(
            $this->getParameter('kernel.root_dir').'/../web/scripts/timeSummaryForJira.js'
        );
        $content = str_replace('https://timetracker/', $ttUrl, $content);

        return new Response(
            $content,
            200,
            ['Content-type' => 'application/javascript']
        );
    }
}
