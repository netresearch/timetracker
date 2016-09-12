<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\Team;
use Netresearch\TimeTrackerBundle\Entity\TeamRepository;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\UserTicketsystem;
use Netresearch\TimeTrackerBundle\Entity\ProjectRepository;
use Netresearch\TimeTrackerBundle\Helper\JiraApiException;
use Netresearch\TimeTrackerBundle\Helper\JiraOAuthApi;
use Netresearch\TimeTrackerBundle\Helper\LdapClient;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;
use Netresearch\TimeTrackerBundle\Entity\EntryRepository;
use Netresearch\TimeTrackerBundle\Entity\User;

use OAuth;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Netresearch\TimeTrackerBundle\Helper;

class DefaultController extends BaseController
{
    public function indexAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->_login($request);
        }

        $userId = (int) $this->_getUserId($request);
        $doctrine = $this->getDoctrine();

        $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($userId);
        $settings = $user->getSettings();

        // Send customers to the frontend for caching
        $customers = $doctrine
            ->getRepository('NetresearchTimeTrackerBundle:Customer')
            ->getCustomersByUser($userId);

        // Send the customer-projects-structure to the frontend for caching
        /* @var $projectRepo ProjectRepository */
        $projectRepo = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project');
        $projects = $projectRepo->getProjectStructure($userId, $customers);

        // Send activities to the frontend for caching
        $activities = $doctrine
            ->getRepository('NetresearchTimeTrackerBundle:Activity')
            ->getActivities();

        return $this->render('NetresearchTimeTrackerBundle:Default:index.html.twig', array(
            'globalConfig'  => [
                'logo_url'              => $this->container->getParameter('app_logo_url'),
                'monthly_overview_url'  => $this->container->getParameter('app_monthly_overview_url'),
                'header_url'            => $this->container->getParameter('app_header_url'),
            ],
            'apptitle'      => $this->container->getParameter('app_title'),
            'environment'   => $this->get('kernel')->getEnvironment(),
            'customers'     => $customers,
            'projects'      => $projects,
            'activities'    => $activities,
            'settings'      => $settings,
            'locale'        => $settings['locale']
        ));
    }

    public function loginAction(Request $request)
    {
        if ($request->getMethod() != 'POST') {
            return $this->render('NetresearchTimeTrackerBundle:Default:login.html.twig',
                array(
                    'locale'  => 'en',
                    'apptitle' => $this->container->getParameter('app_title'),
                )
            );
        }

        $username = $request->request->get('username');
        $password = $request->request->get('password');

        try {

            $client = new LdapClient();
            $client->setLogger($this->get('logger'));

            $client->setHost($this->container->getParameter('ldap_host'))
                ->setPort($this->container->getParameter('ldap_port'))
                ->setReadUser($this->container->getParameter('ldap_readuser'))
                ->setReadPass($this->container->getParameter('ldap_readpass'))
                ->setBaseDn($this->container->getParameter('ldap_basedn'))
                ->setUserName($username)
                ->setUserPass($password)
                ->setUseSSL($this->container->getParameter('ldap_usessl'))
                ->setUserNameField($this->container->getParameter('ldap_usernamefield'))
                ->login();

            $user = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:User')
                ->findOneByUsername($username);

            if (!$user) {
                if (!(boolean) $this->container->getParameter('ldap_create_user')) {
                    throw new \Exception('No equivalent timetracker user could be found.');
                }

                // create new user if users.username doesn't exist for valid ldap-authentication
                $user = new User();
                $user->setUsername($username)
                    ->setType('DEV')
                    ->setShowEmptyLine('0')
                    ->setSuggestTime('1')
                    ->setShowFuture('1')
                    ->setLocale('de');

                if (!empty($client->getTeams())) {
                    /** @var TeamRepository $teamRepo */
                    $teamRepo = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Team');

                    foreach ($client->getTeams() as $teamname) {
                        /** @var Team $team */
                        $team = $teamRepo->findOneBy([
                            'name' => $teamname
                        ]);

                        if ($team) {
                            $user->addTeam($team);
                        }
                    }
                }

                $em = $this->getDoctrine()->getManager();
                $em->persist($user);
                $em->flush();
            }

        } catch (\Exception $e) {

            $this->get('session')->getFlashBag()->add(
                'error', $this->get('translator')->trans($e->getMessage())
            );
            return $this->render('NetresearchTimeTrackerBundle:Default:login.html.twig', array(
                'login'     => false,
                'message'   => $this->get('translator')->trans($e->getMessage()),
                'username'  => $username,
                'locale'    => 'en',
                'apptitle'  => $this->container->getParameter('app_title'),
            ));

        }

        return $this->setLoggedIn($request, $user, $request->request->has('loginCookie'));
    }

    public function logoutAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->_login($request);
        }

        $this->setLoggedOut($request);
        return $this->redirect($this->generateUrl('_start'));
    }

    public function getTimeSummaryAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->_login($request);
        }

        $userId = (int) $this->_getUserId($request);
        $today = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry')->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);

        $data = array(
            'today' => $today,
            'week'  => $week,
            'month' => $month,
        );

        return new Response(json_encode($data));
    }

    /**
     * Retrieves a summary of an entry (project total/own, ticket total/own)
     *
     */
    public function getSummaryAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->_login($request);
        }

        $userId = (int) $this->_getUserId($request);

        $data = array(
            'customer' => array(
                'scope'   => 'customer',
                'name'    => '',
                'entries' => 0,
                'total'   => 0,
                'own'           => 0,
                'estimation'    => 0,
                'quota'         => 0,
            ),
            'project' => array(
                'scope'   => 'project',
                'name'    => '',
                'entries' => 0,
                'total'   => 0,
                'own'           => 0,
                'estimation'    => 0,
                'quota'         => 0,
            ),
            'activity' => array(
                'scope'   => 'activity',
                'name'    => '',
                'entries' => 0,
                'total'   => 0,
                'own'           => 0,
                'estimation'    => 0,
                'quota'         => 0,
            ),
            'ticket' => array(
                'scope'   => 'ticket',
                'name'    => '',
                'entries' => 0,
                'total'   => 0,
                'own'           => 0,
                'estimation'    => 0,
                'quota'         => 0,
            )
        );

        // early exit, if POST parameter for current entry is not given
        $entryId = $request->request->get('id');
        if (!$entryId) {
            return new Response(json_encode($data));
        }

        // Collect all entries data
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry')->getEntrySummary($entryId, $userId, $data);

        if ($data['project']['estimation']) {
            $data['project']['quota'] =
                TimeHelper::formatQuota(
                    $data['project']['total'],
                    $data['project']['estimation']);
        }

        return new Response(json_encode($data));
    }


    /**
     * Retrieves all current entries of the user logged in.
     */
    public function getDataAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->_login($request);
        }

        $userId = (int) $this->_getUserId($request);

        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($userId);

        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 3;
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry')->getEntriesByUser($userId, $days, $user->getShowFuture());

        return new Response(json_encode($data));
    }

    public function getCustomersAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->_login($request);
        }

        $userId = (int) $this->_getUserId($request);
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Customer')->getCustomersByUser($userId);

        return new Response(json_encode($data));
    }

    public function getUsersAction(Request $request)
    {
        if ($this->isHiddenCausedByGDPRViolation()) {
            $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User')->getUserById($this->_getUserId($request));
        } else {
            $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User')->getUsers($this->_getUserId($request));
        }

        return new Response(json_encode($data));
    }

    public function getCustomerAction(Request $request)
    {
        if ($request->get('project')) {
            $project = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:Project')
                ->find($request->get('project'));

            return new Response(json_encode(array('customer' => $project->getCustomer()->getId())));
        }

        return new Response(json_encode(array('customer' => 0)));
    }

    public function getProjectsAction(Request $request)
    {
        $customerId = (int) $request->query->get('customer');
        $userId = (int) $this->_getUserId($request);

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project')->getProjectsByUser($userId, $customerId);

        return new Response(json_encode($data));
    }

    public function getAllProjectsAction(Request $request)
    {
        $customerId = (int) $request->query->get('customer');
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project')->findAll($customerId);

        return new Response(json_encode($data));
    }

    public function getActivitiesAction()
    {
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Activity')->getActivities();
        return new Response(json_encode($data));
    }

    public function getHolidaysAction()
    {
        $holidays = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Holiday')
            ->findByMonth(date("Y"), date("m"));
        return new Response(json_encode($holidays));
    }

    public function exportAction(Request $request)
    {
        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 10000;

        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->_getUserId($request));

        $entries = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Entry')
            ->findByRecentDaysOfUser($user, $days);

        $content = $this->get('templating')->render(
            'NetresearchTimeTrackerBundle:Default:export.csv.twig',
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

    public function jiraOAuthCallbackAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->_getUserId($request));

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Ticketsystem')
            ->find($request->get('tsid'));

        try {
            $jiraOAuthApi = new JiraOAuthApi($user, $ticketSystem, $this->getDoctrine(), $this->container->get('router'));
            $jiraOAuthApi->fetchOAuthAccessToken($request->get('oauth_token'), $request->get('oauth_verifier'));
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);
            return $this->redirectToRoute('_start');
        } catch (JiraApiException $e) {
            return new Response($e->getMessage());
        }
    }
}

