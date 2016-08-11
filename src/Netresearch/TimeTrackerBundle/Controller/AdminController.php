<?php

namespace Netresearch\TimeTrackerBundle\Controller;

//use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\Customer;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\Preset;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;

use \Doctrine AS Doctrine;

class AdminController extends BaseController
{
    public function getAllProjectsAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project')->findAll();

        return new Response(json_encode($data));
    }

    public function getCustomersAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Customer')->getAllCustomers();

        return new Response(json_encode($data));
    }

    public function getUsersAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User')->getAllUsers();

        return new Response(json_encode($data));
    }

    public function getTeamsAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Team')->findAll();
        return new Response(json_encode($data));
    }

    public function getPresetsAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Preset')->getAllPresets();
        return new Response(json_encode($data));
    }

    public function getTicketSystemsAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:TicketSystem')->getAllTicketSystems();
        return new Response(json_encode($data));
    }

    public function saveProjectAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $projectId  = (int) $this->getRequest()->get('id');
        $name       = $this->getRequest()->get('name');

        $ticketSystem = $this->getRequest()->get('ticket_system') ?
            $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
            ->find($this->getRequest()->get('ticket_system'))
            : null;

        $projectLead = $this->getRequest()->get('project_lead') ?
            $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->getRequest()->get('project_lead'))
            : null;

        $technicalLead = $this->getRequest()->get('technical_lead') ?
            $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($this->getRequest()->get('technical_lead'))
            : null;

        $jiraId       = strtoupper($this->getRequest()->get('jiraId'));
        $active       = $this->getRequest()->get('active') ? $this->getRequest()->get('active') : 0;
        $global       = $this->getRequest()->get('global') ? $this->getRequest()->get('global') : 0;
        $estimation   = TimeHelper::readable2minutes($this->getRequest()->get('estimation') ? $this->getRequest()->get('estimation') : '0m');
        $billing      = $this->getRequest()->get('billing') ? $this->getRequest()->get('billing') : 0;
        $costCenter   = $this->getRequest()->get('cost_center') ? $this->getRequest()->get('cost_center') : NULL;
        $offer        = $this->getRequest()->get('offer') ? $this->getRequest()->get('offer') : 0;
        $additionalInformationFromExternal = $this->getRequest()->get('additionalInformationFromExternal') ? $this->getRequest()->get('additionalInformationFromExternal') : 0;
        $projectRepository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project');
        $internalJiraTicketSystem = (int) $this->getRequest()->get('internalJiraTicketSystem', 0);
        $internalJiraProjectKey   = (int) $this->getRequest()->get('internalJiraProjectKey', 0);

        if ($projectId) {
            $project = $projectRepository->find($projectId);
        } else {
            $project = new Project();

            $customer = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:Customer')
                ->find($this->getRequest()->get('customer'));

            if (!$customer) {
                $response = new Response($this->translate('Please choose a customer.'));
                $response->setStatusCode(406);
                return $response;
            }

            $project->setCustomer($customer);
        }

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid project name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if ($sameNamedProject = $projectRepository->findOneBy(array('name' => $name, 'customer' => $project->getCustomer()->getId()))) {
            if ($project->getId() != $sameNamedProject->getId()) {
                $response = new Response($this->translate('The project name provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        if ((1 < strlen($jiraId)) && ($project->getJiraId() !== $jiraId))  {
            $search = array('jiraId' => $jiraId);
            if ($ticketSystem) {
                $search['ticketSystem'] = $ticketSystem;
            }
        }

        if (strlen($jiraId) && false == $projectRepository->isValidJiraPrefix($jiraId, $project)) {
            $response = new Response($this->translate('Please provide a valid ticket prefix with only capital letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        $project
            ->setName($name)
            ->setTicketSystem($ticketSystem)
            ->setJiraId($jiraId)
            ->setActive($active)
            ->setGlobal($global)
            ->setEstimation($estimation)
            ->setProjectLead($projectLead)
            ->setTechnicalLead($technicalLead)
            ->setBilling($billing)
            ->setOffer($offer)
            ->setCostCenter($costCenter)
            ->setAdditionalInformationFromExternal($additionalInformationFromExternal)
            ->setInternalJiraProjectKey($internalJiraProjectKey)
            ->setInternalJiraTicketSystem($internalJiraTicketSystem);

        $em = $this->getDoctrine()->getEntityManager();
        $em->persist($project);
        $em->flush();

        $data = array($project->getId(), $name, $project->getCustomer()->getId(), $jiraId);

        return new Response(json_encode($data));
    }

    public function saveCustomerAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $customerId  = (int) $this->getRequest()->get('id');
        $name       = $this->getRequest()->get('name');
        $active     = $this->getRequest()->get('active') ? $this->getRequest()->get('active') : 0;
        $global     = $this->getRequest()->get('global') ? $this->getRequest()->get('global') : 0;
        $teamIds    = $this->getRequest()->get('teams')  ? $this->getRequest()->get('teams')  : array();

        $customerRepository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Customer');

        if ($customerId) {
            $customer = $customerRepository->find($customerId);
        } else {
            $customer = new Customer();
        }

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid customer name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if ($sameNamedCustomer = $customerRepository->findOneByName($name)) {
            if ($customer->getId() != $sameNamedCustomer->getId()) {
                $response = new Response($this->translate('The customer name provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        $customer->setName($name)->setActive($active)->setGlobal($global);

        $customer->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }
            if ($team = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Team')->find( (int) $teamId)) {
                $customer->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(406);
                return $response;
            }
        }

        if (0 == $customer->getTeams()->count() && false == $global) {
            $response = new Response($this->translate('Every customer must belong to at least one team if it is not global.'));
            $response->setStatusCode(406);
            return $response;
        }

        $em = $this->getDoctrine()->getEntityManager();
        $em->persist($customer);
        $em->flush();

        $data = array($customer->getId(), $name, $active, $global, $teamIds);

        return new Response(json_encode($data));
    }

    public function saveUserAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId   = (int) $this->getRequest()->get('id');
        $name     = $this->getRequest()->get('username');
        $abbr     = $this->getRequest()->get('abbr');
        $type     = $this->getRequest()->get('type');
        $locale   = $this->getRequest()->get('locale');
        $teamIds  = $this->getRequest()->get('teams')  ? $this->getRequest()->get('teams')  : array();

        $userRepository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User');

        if ($userId) {
            $user = $userRepository->find($userId);
        } else {
            $user = new User();
        }

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid user name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if (strlen($abbr) < 3) {
            $response = new Response($this->translate('Please provide a valid user name abbreviation with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if ($sameNamedUser = $userRepository->findOneByUsername($name)) {
            if ($user->getId() != $sameNamedUser->getId()) {
                $response = new Response($this->translate('The user name provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        if ($sameAbbrUser = $userRepository->findOneByAbbr($abbr)) {
            if ($user->getId() != $sameAbbrUser->getId()) {
                $response = new Response($this->translate('The user name abreviation provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        $user->setUsername($name)
            ->setAbbr($abbr)
            ->setLocale($locale)
            ->setType($type)
            ->setShowEmptyLine(0)
            ->setSuggestTime(1)
            ->setShowFuture(1);

        $user->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }
            if ($team = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Team')->find((int)$teamId)) {
                $user->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(406);
                return $response;
            }
        }

        if (0 == $user->getTeams()->count()) {
            $response = new Response($this->translate('Every user must belong to at least one team'));
            $response->setStatusCode(406);
            return $response;
        }

        $em = $this->getDoctrine()->getEntityManager();
        $em->persist($user);
        $em->flush();

        $data = array($user->getId(), $name, $abbr, $type);
        return new Response(json_encode($data));
    }

    public function deletePresetAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $id             = (int) $this->getRequest()->get('id');
        $doctrine = $this->getDoctrine();

        $entry = $doctrine->getRepository('NetresearchTimeTrackerBundle:Preset')
                ->find($id);

        $em = $doctrine->getEntityManager();
        $em->remove($entry);
        $em->flush();

        return new Response(json_encode(array('success' => true)));
    }

    public function savePresetAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $id             = (int) $this->getRequest()->get('id');
        $name           = $this->getRequest()->get('name');
        $customer       = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Customer')
                        ->find($this->getRequest()->get('customer'));
        $project        = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Project')
                        ->find($this->getRequest()->get('project'));
        $activity       = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Activity')
                        ->find($this->getRequest()->get('activity'));
        $description    = $this->getRequest()->get('description');

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Preset');

        if ($id) {
            $preset = $repository->find($id);
        } else {
            $preset = new Preset();
        }

        try {
            $preset->setName($name)
                ->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity)
                ->setDescription($description);

            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($preset);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(403);
            return $response;
        }

        return new Response(json_encode($preset->toArray()));
    }



    public function saveTicketSystemAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:TicketSystem');

        $id             = (int) $this->getRequest()->get('id');
        $name           = $this->getRequest()->get('name');
        $type           = $this->getRequest()->get('type');
        $bookTime       = $this->getRequest()->get('bookTime');
        $url            = $this->getRequest()->get('url');
        $ticketurl      = $this->getRequest()->get('ticketurl');
        $login          = $this->getRequest()->get('login');
        $password       = $this->getRequest()->get('password');
        $publicKey      = $this->getRequest()->get('publicKey');
        $privateKey     = $this->getRequest()->get('privateKey');
        $ticketUrl      = $this->getRequest()->get('ticketUrl');

        if ($id) {
            $ticketSystem = $repository->find($id);
        } else {
            $ticketSystem = new TicketSystem();
        }

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid ticket system name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if ($sameNamedSystem = $repository->findOneByName($name)) {
            if ($ticketSystem->getId() != $sameNamedSystem->getId()) {
                $response = new Response($this->translate('The ticket system name provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        try {
            $ticketSystem
                ->setName($name)
                ->setType($type)
                ->setBookTime((boolean) $bookTime)
                ->setUrl($url)
                ->setTicketUrl($ticketurl)
                ->setLogin($login)
                ->setPassword($password)
                ->setPublicKey($publicKey)
                ->setPrivateKey($privateKey)
                ->setTicketUrl($ticketUrl);

            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        return new Response(json_encode($ticketSystem->toArray()));
    }

}
