<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use ReflectionException;
use Psr\Log\LoggerInterface;
use Exception;
use Twig\Error\Error;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Entity\TicketSystem;
use App\Helper\JiraApiException;
use App\Helper\JiraOAuthApi;
use App\Helper\TimeHelper;
use App\Repository\EntryRepository;
use App\Entity\User;
use App\Entity\User\Types;
use App\Kernel;
use App\Model\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Class DefaultController.
 */
class DefaultController extends BaseController
{
    /**
     * @throws ReflectionException
     *
     * @return Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    /**
     * @throws ReflectionException
     *
     * @return Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    #[Route(path: '/', name: '_start')]
    public function indexAction(Kernel $kernel): Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $user = $this->getWorkUser();
        $settings = $user?->getSettings();

        if ($user) {
            // Send customers to the frontend for caching
            $customers = $this->doctrine
                ->getRepository('App:Customer')
                ->getCustomersByUser($user->getId())
            ;
            // Send the customer-projects-structure to the frontend for caching
            /** @var \App\Repository\ProjectRepository $projectRepo */
            $projectRepo = $this->doctrine->getRepository('App:Project');
            $projects    = $projectRepo->getProjectStructure($user->getId(), $customers);
        }

        // these settings are used to render frontend according to user settings and permissions
        // that's why we need to override 'type' for user accordingly to permission level
        if ($this->isGranted('ROLE_PL')) {
            $settings['type'] = Types::PL;
        } elseif ($this->isGranted('ROLE_CTL')) {
            $settings['type'] = Types::CTL;
        } else {
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

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getTimeSummary', name: 'time_summary')]
    public function getTimeSummaryAction(): Response|RedirectResponse
    {
        $userId = (int) $this->getUserId();
        $today  = $this->doctrine->getRepository('App:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week   = $this->doctrine->getRepository('App:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month  = $this->doctrine->getRepository('App:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);
        $data   = [
            'today' => $today,
            'week'  => $week,
            'month' => $month,
        ];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Retrieves a summary of an entry (project total/own, ticket total/own).
     *
     * @throws \Doctrine\DBAL\Exception
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
        $data = $this->doctrine->getRepository('App:Entry')->getEntrySummary($entryId, $userId, $data);
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
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getData/days/{days}', name: '_getDataDays')]
    #[Route(path: '/getData', name: '_getData')]
    public function getDataAction(int $days = 3): Response|RedirectResponse
    {
        $result = [];

        $user = $this->getWorkUser();

        //$days = $this->request->attributes->has('days') ? (int) $this->request->attributes->get('days') : 3;
        $data = $this->doctrine->getRepository('App:Entry')->getEntriesByUser($user->getId(), $days, $user->getShowFuture());

        // BC - convert object into array
        foreach ($data as $entry) {
            $result[] = ['entry' => $entry->toArray()];
        }

        return new Response(json_encode($result, \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getCustomers', name: '_getCustomers')]
    public function getCustomersAction(): Response|RedirectResponse
    {
        $data = $this->doctrine->getRepository('App:Customer')->getCustomersByUser($this->getUserId());

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Developers may see their own data only, CTL and PL may see everyone.
     */
    #[Route(path: '/getUsers', name: '_getUsers')]
    public function getUsersAction(): Response
    {
        $data   = [];
        $userId = $this->getUserId();

        if (null === $userId) {
            return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
        }

        if ($this->isGranted('ROLE_DEV')) {
            $data = $this->doctrine->getRepository('App:User')->getUserById($userId);
        } else {
            $data = $this->doctrine->getRepository('App:User')->getUsers($userId);
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return Response
     */
    #[Route(path: '/getCustomer', name: '_getCustomer')]
    public function getCustomerAction()
    {
        if ($this->request->get('project')) {
            $project = $this->doctrine
                ->getRepository('App:Project')
                ->find($this->request->get('project'))
            ;

            return new Response(json_encode(['customer' => $project->getCustomer()->getId()], \JSON_THROW_ON_ERROR));
        }

        return new Response(json_encode(['customer' => 0]));
    }

    /**
     * @throws ReflectionException
     *
     * @return Response
     */
    #[Route(path: '/getProjects', name: '_getProjects')]
    public function getProjectsAction()
    {
        $customerId = (int) $this->request->query->get('customer');
        $userId     = (int) $this->getUserId();
        $data       = $this->doctrine->getRepository('App:Project')->getProjectsByUser($userId, $customerId);

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws ReflectionException
     *
     * @return Response
     */
    #[Route(path: '/getAllProjects', name: '_getAllProjects')]
    public function getAllProjectsAction()
    {
        $customerId = (int) $this->request->query->get('customer');
        if ($customerId > 0) {
            $result = $this->doctrine->getRepository('App:Project')->findByCustomer($customerId);
        } else {
            $result = $this->doctrine->getRepository('App:Project')->findAll();
        }
        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return Response
     */
    #[Route(path: '/getActivities', name: '_getActivities')]
    public function getActivitiesAction()
    {
        $data = $this->doctrine->getRepository('App:Activity')->getActivities();

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return Response
     */
    #[Route(path: '/getHolidays', name: '_getHolidays')]
    public function getHolidaysAction()
    {
        $holidays = $this->doctrine
            ->getRepository('App:Holiday')
            ->findByMonth(date('Y'), date('m'))
        ;

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

        $entries = $this->doctrine
            ->getRepository('App:Entry')
            ->findByRecentDaysOfUser($this->getWorkUser(), $days)
        ;
        $content = $this->get('templating')->render(
            'App:Default:export.csv.twig',
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

    /**
     * Handles returning user from OAuth service.
     *
     * User is redirected to app after accepting or declining granting access for this app.
     */
    #[Route(path: '/jiraoauthcallback')]
    public function jiraOAuthCallbackAction(): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $this->doctrine
            ->getRepository('App:User')
            ->find($this->getUserId())
        ;

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->doctrine
            ->getRepository('App:Ticketsystem')
            ->find($this->request->get('tsid'))
        ;

        try {
            $jiraOAuthApi = new JiraOAuthApi($user, $ticketSystem, $this->doctrine, $this->container->get('router'));
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
        $activities = $this->doctrine->getRepository(
            'App:Entry'
        )->getActivitiesWithTime($ticket);
        $users = $this->doctrine->getRepository(
            'App:Entry'
        )->getUsersWithTime($ticket);
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
