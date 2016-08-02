<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\TicketSystemRepository;
use Netresearch\TimeTrackerBundle\Entity\HolidayRepository;

use Netresearch\TimeTrackerBundle\Helper\LdapClient;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;
use Netresearch\TimeTrackerBundle\Helper\LocalizationHelper;

use Netresearch\TimeTrackerBundle\Entity\EntryRepository;

use Netresearch\TimeTrackerBundle\Entity\Entry as Entry;
use Netresearch\TimeTrackerBundle\Entity\User as User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Netresearch\TimeTrackerBundle\Helper;

use \Zend_Ldap as Zend_Ldap;
use \Zend_Ldap_Exception as Zend_Ldap_Exception;
use \Zend_Ldap_Dn as Zend_Ldap_Dn;
use \Zend_Ldap_Collection AS Zend_Ldap_Collection;
use \Zend_Ldap_Collection_Iterator_Default AS Zend_Ldap_Collection_Iterator_Default;
use \Doctrine AS Doctrine;

class DefaultController extends BaseController
{
    public function indexAction()
    {
        if (!$this->checkLogin()) {
            return $this->_login();
        }

        $userId = (int) $this->_getUserId();
        $doctrine = $this->getDoctrine();

        $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($userId);
        $settings = $user->getSettings();

        // Send customers to the frontend for caching
        $customers = $doctrine
            ->getRepository('NetresearchTimeTrackerBundle:Customer')
            ->getCustomersByUser($userId);

        // Send the customer-projects-structure to the frontend for caching
        $projectRepo = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project');
        $projects = $projectRepo->getProjectStructure($userId, $customers);

        // Send activities to the frontend for caching
        $activities = $doctrine
            ->getRepository('NetresearchTimeTrackerBundle:Activity')
            ->getActivities();

        return $this->render('NetresearchTimeTrackerBundle:Default:index.html.twig', array(
            'environment'   => $this->get('kernel')->getEnvironment(),
            'customers'     => json_encode($customers),
            'projects'      => json_encode($projects),
            'activities'    => json_encode($activities),
            'settings'      => json_encode($settings),
            'locale'        => $settings['locale']
        ));
    }

    public function loginAction()
    {
        $request = $this->getRequest();

        if ($request->getMethod() != 'POST') {
            return $this->render('NetresearchTimeTrackerBundle:Default:login.html.twig',
                array('locale'  => 'en')
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
                ->setBaseDn($this->container->getParameter('ldap_basedn'))
                ->setUserName($username)
                ->setUserPass($password)
                ->setUseSSL($this->container->getParameter('ldap_usessl'))
                ->setUserNameField($this->container->getParameter('ldap_usernamefield'))
                ->login();

        } catch (\Exception $e) {

            $request->getSession()->setFlash('error', $this->get('translator')->trans($e->getMessage()));
            return $this->render('NetresearchTimeTrackerBundle:Default:login.html.twig', array(
                'login'     => false, 
                'message'   => $this->get('translator')->trans($e->getMessage()),
                'username'  => $username,
                'locale'    => 'en'
            ));

        }

        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->findOneByUsername($username);

        if (!$user) {
            // create new user if users.username doesn't exist for valid ldap-authentication
            $user = new User();
            $user->setUsername($username)
                ->setType('DEV')
                ->setShowEmptyLine('0')
                ->setSuggestTime('1')
                ->setShowFuture('1')
                ->setLocale('de');

            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($user);
            $em->flush();
        }

        return $this->setLoggedIn($user, $request->request->has('loginCookie'));
    }

    public function logoutAction()
    {
        if (!$this->checkLogin()) {
            return $this->_login();
        }

        $this->setLoggedOut();
        return $this->redirect($this->generateUrl('_start'));
    }

    public function getTimeSummaryAction()
    {
        if (!$this->checkLogin()) {
            return $this->_login();
        }

        $userId = (int) $this->_getUserId();
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
    public function getSummaryAction()
    {
        if (!$this->checkLogin()) {
            return $this->_login();
        }

        $userId = (int) $this->_getUserId();

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
        $entryId = $this->getRequest()->request->get('id');
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
    public function getDataAction()
    {
        if (!$this->checkLogin()) {
            return $this->_login();
        }

        $userId = (int) $this->_getUserId();

        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($userId);

        $days = $this->getRequest()->attributes->has('days') ? (int) $this->getRequest()->attributes->get('days') : 3;
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry')->getEntriesByUser($userId, $days, $user->getShowFuture());

        return new Response(json_encode($data));
    }

    public function getCustomersAction()
    {
        if (!$this->checkLogin()) {
            return $this->_login();
        }

        $userId = (int) $this->_getUserId();
        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Customer')->getCustomersByUser($userId);

        return new Response(json_encode($data));
    }

    public function getUsersAction()
    {

        if ($this->isHiddenCausedByGDPRViolation()) {
            $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User')->getUserById($this->_getUserId());
        } else {
            $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User')->getUsers($this->_getUserId());
        }

        return new Response(json_encode($data));
    }

    public function getCustomerAction()
    {
        $request = $this->getRequest();

        if ($request->get('project')) {
            $project = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:Project')
                ->find($request->get('project'));

            return new Response(json_encode(array('customer' => $project->getCustomer()->getId())));
        }

        return new Response(json_encode(array('customer' => 0)));
    }

    public function getProjectsAction()
    {
        $customerId = (int) $this->getRequest()->query->get('customer');
        $userId = (int) $this->_getUserId();

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project')->getProjectsByUser($userId, $customerId);

        return new Response(json_encode($data));
    }

    public function getAllProjectsAction()
    {
        $customerId = (int) $this->getRequest()->query->get('customer');
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

    public function exportAction()
    {
        $days = $this->getRequest()->attributes->has('days') ? (int) $this->getRequest()->attributes->get('days') : 10000;

        $user = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->_getUserId());

        $entries = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Entry')
            ->findByRecentDaysOfUser($user, $days);

        $content = $this->get('templating')->render(
            'NetresearchTimeTrackerBundle:Default:export.csv.twig',
            array('entries' => $entries)
        );

        $filename = strtolower(str_replace(' ', '-', $user->getUsername())) . '.csv';

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-disposition', 'attachment;filename=' . $filename);
        $response->setContent(chr(239) . chr(187) . chr(191) . $content);

        return $response;
    }

}

