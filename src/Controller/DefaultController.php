<?php

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
use App\Helper\LdapClient;
use App\Helper\TimeHelper;
use App\Repository\EntryRepository;
use App\Entity\User;
use App\Kernel;
use App\Model\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class DefaultController
 * @package App\Controller
 */
class DefaultController extends BaseController
{
    /**
     * @return Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws ReflectionException
     */
    /**
     * @return Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws ReflectionException
     */
    #[Route(path: '/', name: '_start')]
    public function indexAction(Kernel $kernel) : Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        if (!$this->checkLogin()) {
            return $this->login();
        }
        $userId = (int) $this->getUserId();
        $doctrine = $this->doctrine;
        $user = $doctrine->getRepository('App:User')->find($userId);
        $settings = $user->getSettings();
        // Send customers to the frontend for caching
        $customers = $doctrine
            ->getRepository('App:Customer')
            ->getCustomersByUser($userId);
        // Send the customer-projects-structure to the frontend for caching
        /* @var $projectRepo \App\Repository\ProjectRepository */
        $projectRepo = $doctrine->getRepository('App:Project');
        $projects = $projectRepo->getProjectStructure($userId, $customers);
        return $this->render('index.html.twig', array(
            'globalConfig'  => [
                'logo_url'              => $this->params->get('app.logo_url'),
                'monthly_overview_url'  => $this->params->get('app.monthly_overview_url'),
                'header_url'            => $this->params->get('app.header_url'),
            ],
            'environment'   => $kernel->getEnvironment(),
            'customers'     => $customers,
            'projects'      => $projects,
            'settings'      => $settings,
            'locale'        => $settings['locale']
        ));
    }

    /**
     * @return Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    /**
     * @return Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    #[Route(path: '/login', name: '_login')]
    public function loginAction(LoggerInterface $logger) : Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $client = null;
        if ($this->request->getMethod() != 'POST') {
            return $this->render('login.html.twig',
                array(
                    'locale'  => 'en',
                )
            );
        }
        $username = $this->request->get('username');
        $password = $this->request->get('password');

        $ldap = false;

        try {

            if ($ldap) {

                $client = new LdapClient($logger);

                $client->setHost($this->params->get('ldap_host'))
                    ->setPort($this->params->get('ldap_port'))
                    ->setReadUser($this->params->get('ldap_readuser'))
                    ->setReadPass($this->params->get('ldap_readpass'))
                    ->setBaseDn($this->params->get('ldap_basedn'))
                    ->setUserName($username)
                    ->setUserPass($password)
                    ->setUseSSL($this->params->get('ldap_usessl'))
                    ->setUserNameField($this->params->get('ldap_usernamefield'))
                    ->login();
            }

            $user = $this->doctrine
                ->getRepository('App:User')
                ->findOneByUsername($username);

            if (!$user) {
                if ($ldap && !(boolean) $this->params->get('ldap_create_user')) {
                    throw new Exception('No equivalent timetracker user could be found.');
                }

                // create new user if users.username doesn't exist for valid ldap-authentication
                $user = new User();
                $user->setUsername($username)
                    ->setType('DEV')
                    ->setShowEmptyLine('0')
                    ->setSuggestTime('1')
                    ->setShowFuture('1')
                    ->setLocale('de');

                if ($ldap && !empty($client->getTeams())) {
                    /** @var TeamRepository $teamRepo */
                    $teamRepo = $this->doctrine
                        ->getRepository('App:Team');

                    foreach ($client->getTeams() as $team_name) {
                        /** @var Team $team */
                        $team = $this->repository->findOneBy([
                            'name' => $team_name
                        ]);

                        if ($team) {
                            $user->addTeam($team);
                        }
                    }
                }

                $em = $this->doctrine->getManager();
                $em->persist($user);
                $em->flush();
            }

        } catch (Exception $e) {

            $this->session->getFlashBag()->add(
                'error', $this->t($e->getMessage())
            );
            return $this->render('login.html.twig', array(
                'login'     => false,
                'message'   => $this->t($e->getMessage()),
                'username'  => $username,
                'locale'    => 'en',
            ));

        }

        return $this->setLoggedIn($user);
    }

    #[Route(path: '/logout', name: '_logout')]
    public function logoutAction() : Response|RedirectResponse
    {
        if (!$this->checkLogin()) {
            return $this->login();
        }
        $this->setLoggedOut();
        return $this->redirectToRoute('_start');
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getTimeSummary', name: 'time_summary')]
    public function getTimeSummaryAction() : Response|RedirectResponse
    {
        if (!$this->checkLogin()) {
            return $this->login();
        }
        $userId = (int) $this->getUserId();
        $today = $this->doctrine->getRepository('App:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week = $this->doctrine->getRepository('App:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month = $this->doctrine->getRepository('App:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);
        $data = array(
            'today' => $today,
            'week'  => $week,
            'month' => $month,
        );
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Retrieves a summary of an entry (project total/own, ticket total/own)
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getSummary', name: '_getSummary')]
    public function getSummaryAction() : Response|RedirectResponse
    {
        if (!$this->checkLogin()) {
            return $this->login();
        }
        $userId = (int) $this->getUserId();
        $data = array(
            'customer' => array(
                'scope'      => 'customer',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ),
            'project' => array(
                'scope'      => 'project',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ),
            'activity' => array(
                'scope'      => 'activity',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ),
            'ticket' => array(
                'scope'      => 'ticket',
                'name'       => '',
                'entries'    => 0,
                'total'      => 0,
                'own'        => 0,
                'estimation' => 0,
                'quota'      => 0,
            ),
        );
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
                    $data['project']['estimation']);
        }
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }


    /**
     * Retrieves all current entries of the user logged in.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getData/days/{days}', name: '_getDataDays')]
    #[Route(path: '/getData', name: '_getData')]
    public function getDataAction(int $days = 3) : Response|RedirectResponse
    {
        if (!$this->checkLogin()) {
            return $this->login();
        }
        $userId = (int) $this->getUserId();
        $user = $this->doctrine
            ->getRepository('App:User')
            ->find($userId);
        //$days = $this->request->attributes->has('days') ? (int) $this->request->attributes->get('days') : 3;
        $data = $this->doctrine->getRepository('App:Entry')->getEntriesByUser($userId, $days, $user->getShowFuture());
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(path: '/getCustomers', name: '_getCustomers')]
    public function getCustomersAction() : Response|RedirectResponse
    {
        if (!$this->checkLogin()) {
            return $this->login();
        }
        $userId = (int) $this->getUserId();
        $data = $this->doctrine->getRepository('App:Customer')->getCustomersByUser($userId);
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Developers may see their own data only, CTL and PL may see everyone.
     */
    #[Route(path: '/getUsers', name: '_getUsers')]
    public function getUsersAction(): Response
    {
        if ($this->isDEV()) {
            $data = $this->doctrine->getRepository('App:User')->getUserById($this->getUserId());
        } else {
            $data = $this->doctrine->getRepository('App:User')->getUsers($this->getUserId());
        }
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
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
                ->find($this->request->get('project'));

            return new Response(json_encode(array('customer' => $project->getCustomer()->getId()), JSON_THROW_ON_ERROR));
        }
        return new Response(json_encode(array('customer' => 0)));
    }

    /**
     * @return Response
     * @throws ReflectionException
     */
    #[Route(path: '/getProjects', name: '_getProjects')]
    public function getProjectsAction()
    {
        $customerId = (int) $this->request->query->get('customer');
        $userId = (int) $this->getUserId();
        $data = $this->doctrine->getRepository('App:Project')->getProjectsByUser($userId, $customerId);
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return Response
     * @throws \Doctrine\DBAL\Exception
     * @throws ReflectionException
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
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return Response
     */
    #[Route(path: '/getActivities', name: '_getActivities')]
    public function getActivitiesAction()
    {
        $data = $this->doctrine->getRepository('App:Activity')->getActivities();
        return new Response(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return Response
     */
    #[Route(path: '/getHolidays', name: '_getHolidays')]
    public function getHolidaysAction()
    {
        $holidays = $this->doctrine
            ->getRepository('App:Holiday')
            ->findByMonth(date("Y"), date("m"));
        return new Response(json_encode($holidays, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws Error
     * @throws Exception
     */
    #[Route(path: '/export/{days}', name: '_export')]
    public function exportAction(int $days = 10000) : Response
    {
        //$days = $this->request->attributes->has('days') ? (int) $this->request->attributes->get('days') : 10000;
        $user = $this->doctrine
            ->getRepository('App:User')
            ->find($this->getUserId());
        $entries = $this->doctrine
            ->getRepository('App:Entry')
            ->findByRecentDaysOfUser($user, $days);
        $content = $this->get('templating')->render(
            'App:Default:export.csv.twig',
            array(
                'entries' => $entries,
                'labels'  => null,
            )
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
    #[Route(path: '/jiraoauthcallback')]
    public function jiraOAuthCallbackAction(): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $this->doctrine
            ->getRepository('App:User')
            ->find($this->getUserId());

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->doctrine
            ->getRepository('App:Ticketsystem')
            ->find($this->request->get('tsid'));

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
     * Get a list of information (activities, times, users) about a ticket for time evaluation
     *
     * @return object JSON data with time information about activities, total time and users
     */
    #[Route(path: '/getTicketSummary/{ticket}', name: '_getTicketTimeSummary')]
    public function getTicketTimeSummaryAction(string $ticket = null)
    {
        $time = [];
        if (!$this->checkLogin()) {
            return $this->login();
        }
        //$attributes = $this->request->attributes;
        //$ticket = $attributes->has('ticket') ? $attributes->get('ticket') : null;
        $activities = $this->doctrine->getRepository(
            'App:Entry'
        )->getActivitiesWithTime($ticket);
        $users = $this->doctrine->getRepository(
            'App:Entry'
        )->getUsersWithTime($ticket);
        if (is_null($ticket) || empty($users)) {
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
        $time['total_time']['seconds'] = (int) $time['total_time']['time'] * 60;
        $time['total_time']['time'] = TimeHelper::minutes2readable(
            $time['total_time']['time']
        );
        return new Response(
            json_encode($time, JSON_THROW_ON_ERROR),
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
            '_start', [], UrlGeneratorInterface::ABSOLUTE_URL
        );
        $content = file_get_contents(
            $this->params->get('kernel.root_dir')
            . '/../web/scripts/timeSummaryForJira.js'
        );
        $content = str_replace('https://timetracker/', $ttUrl, $content);
        return new Response(
            $content,
            200,
            ['Content-type' => 'application/javascript']
        );
    }
}
