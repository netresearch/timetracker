<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Repository\UserRepository;
use Netresearch\TimeTrackerBundle\Model\Response;
use Netresearch\TimeTrackerBundle\Entity\Contract;
use Netresearch\TimeTrackerBundle\Entity\Team;
use Netresearch\TimeTrackerBundle\Helper\JiraOAuthApi;
use Netresearch\TimeTrackerBundle\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\Customer;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\Preset;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\Activity;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;

/**
 * Class AdminController
 * @package Netresearch\TimeTrackerBundle\Controller
 */
class AdminController extends BaseController
{
    /**
     * @param Request $request
     * @return Response
     * @throws \ReflectionException
     */
    public function getAllProjectsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $result = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project')->findAll();

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new Response(json_encode($data));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getCustomersAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Repository\CustomerRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Customer');

        return new Response(json_encode($repo->getAllCustomers()));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getUsersAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Repository\UserRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User');

        return new Response(json_encode($repo->getAllUsers()));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getTeamsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Repository\TeamRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Team');

        return new Response(json_encode($repo->findAll()));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getPresetsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Repository\PresetRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Preset');

        return new Response(json_encode($repo->getAllPresets()));
    }

    /**
     * @param Request $request
     * @return Response
     * @throws \ReflectionException
     */
    public function getTicketSystemsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Repository\TicketSystemRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:TicketSystem');
        $ticketSystems = $repo->getAllTicketSystems();

        if (false === $this->isPl($request)) {
            $c = count($ticketSystems);
            for ($i = 0; $i < $c; $i++) {
                unset($ticketSystems[$i]['ticketSystem']['login']);
                unset($ticketSystems[$i]['ticketSystem']['password']);
                unset($ticketSystems[$i]['ticketSystem']['publicKey']);
                unset($ticketSystems[$i]['ticketSystem']['privateKey']);
                unset($ticketSystems[$i]['ticketSystem']['oauthConsumerSecret']);
                unset($ticketSystems[$i]['ticketSystem']['oauthConsumerKey']);
            }
        }

        return new Response(json_encode($ticketSystems));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function saveProjectAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $projectId  = (int) $request->get('id');
        $name       = $request->get('name');

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $request->get('ticket_system') ?
            $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
            ->find($request->get('ticket_system'))
            : null;

        $projectLead = $request->get('project_lead') ?
            $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($request->get('project_lead'))
            : null;

        $technicalLead = $request->get('technical_lead') ?
            $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->find($request->get('technical_lead'))
            : null;

        $jiraId       = strtoupper($request->get('jiraId'));
        $active       = $request->get('active') ? $request->get('active') : 0;
        $global       = $request->get('global') ? $request->get('global') : 0;
        $estimation   = TimeHelper::readable2minutes($request->get('estimation') ? $request->get('estimation') : '0m');
        $billing      = $request->get('billing') ? $request->get('billing') : 0;
        $costCenter   = $request->get('cost_center') ? $request->get('cost_center') : NULL;
        $offer        = $request->get('offer') ? $request->get('offer') : 0;
        $additionalInformationFromExternal = $request->get('additionalInformationFromExternal') ? $request->get('additionalInformationFromExternal') : 0;
        /* @var $projectRepository \Netresearch\TimeTrackerBundle\Repository\ProjectRepository */
        $projectRepository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project');
        $internalJiraTicketSystem = (int) $request->get('internalJiraTicketSystem', 0);
        $internalJiraProjectKey   = $request->get('internalJiraProjectKey', 0);

        if ($projectId) {
            $project = $projectRepository->find($projectId);
        } else {
            $project = new Project();

            /** @var Customer $customer */
            $customer = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:Customer')
                ->find($request->get('customer'));

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

        $sameNamedProject = $projectRepository->findOneBy(
            array('name' => $name, 'customer' => $project->getCustomer()->getId())
        );
        if ($sameNamedProject) {
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

        if (strlen($jiraId) && false == $projectRepository->isValidJiraPrefix($jiraId)) {
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

        $em = $this->getDoctrine()->getManager();
        $em->persist($project);
        $em->flush();

        $data = array($project->getId(), $name, $project->getCustomer()->getId(), $jiraId);

        return new Response(json_encode($data));
    }

    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteProjectAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $project = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($project);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function saveCustomerAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $customerId  = (int) $request->get('id');
        $name       = $request->get('name');
        $active     = $request->get('active') ? $request->get('active') : 0;
        $global     = $request->get('global') ? $request->get('global') : 0;
        $teamIds    = $request->get('teams')  ? $request->get('teams')  : array();

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

        $em = $this->getDoctrine()->getManager();
        $em->persist($customer);
        $em->flush();

        $data = array($customer->getId(), $name, $active, $global, $teamIds);

        return new Response(json_encode($data));
    }

    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteCustomerAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $customer = $doctrine->getRepository('NetresearchTimeTrackerBundle:Customer')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($customer);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function saveUserAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId   = (int) $request->get('id');
        $name     = $request->get('username');
        $abbr     = $request->get('abbr');
        $type     = $request->get('type');
        $locale   = $request->get('locale');
        $teamIds  = $request->get('teams')  ? $request->get('teams')  : array();

        /* @var UserRepository $userRepository */
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

        $em = $this->getDoctrine()->getManager();
        $em->persist($user);
        $em->flush();

        $data = array($user->getId(), $name, $abbr, $type);
        return new Response(json_encode($data));
    }

    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteUserAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($user);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }

    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deletePresetAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $preset = $doctrine->getRepository('NetresearchTimeTrackerBundle:Preset')
                    ->find($id);

            $em = $doctrine->getManager();
            $em->remove($preset);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function savePresetAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id          = (int) $request->get('id');
        $name        = $request->get('name');
        $customer    = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Customer')
            ->find($request->get('customer'));
        $project     = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Project')
            ->find($request->get('project'));
        $activity    = $this->getDoctrine()
            ->getRepository('NetresearchTimeTrackerBundle:Activity')
            ->find($request->get('activity'));
        $description = $request->get('description');

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

            $em = $this->getDoctrine()->getManager();
            $em->persist($preset);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(403);
            return $response;
        }

        return new Response(json_encode($preset->toArray()));
    }


    /**
     * @param Request $request
     * @return Response
     * @throws \ReflectionException
     */
    public function saveTicketSystemAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:TicketSystem');

        $id                  = (int) $request->get('id');
        $name                = $request->get('name');
        $type                = $request->get('type');
        $bookTime            = $request->get('bookTime');
        $url                 = $request->get('url');
        $login               = $request->get('login');
        $password            = $request->get('password');
        $publicKey           = $request->get('publicKey');
        $privateKey          = $request->get('privateKey');
        $ticketUrl           = $request->get('ticketUrl');
        $oauthConsumerKey    = $request->get('oauthConsumerKey');
        $oauthConsumerSecret = $request->get('oauthConsumerSecret');

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
                ->setTicketUrl($ticketUrl)
                ->setLogin($login)
                ->setPassword($password)
                ->setPublicKey($publicKey)
                ->setPrivateKey($privateKey)
                ->setOauthConsumerKey($oauthConsumerKey)
                ->setOauthConsumerSecret($oauthConsumerSecret);

            $em = $this->getDoctrine()->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        return new Response(json_encode($ticketSystem->toArray()));
    }


    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteTicketSystemAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $ticketSystem = $doctrine->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($ticketSystem);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }


    /**
     * @param Request $request
     * @return Response
     */
    public function saveActivityAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Activity');

        $id             = (int) $request->get('id');
        $name           = $request->get('name');
        $needsTicket    = (boolean) $request->get('needsTicket');
        $factor         = str_replace(',', '.', $request->get('factor'));

        if ($id) {
            $activity = $repository->find($id);
        } else {
            $activity = new Activity();
        }

        if ($sameNamedActivity = $repository->findOneByName($name)) {
            if ($activity->getId() != $sameNamedActivity->getId()) {
                $response = new Response($this->translate('The activity name provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        try {
            $activity
                ->setName($name)
                ->setNeedsTicket($needsTicket)
                ->setFactor($factor);

            $em = $this->getDoctrine()->getManager();
            $em->persist($activity);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = array($activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor());

        return new Response(json_encode($data));
    }


    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteActivityAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $activity = $doctrine->getRepository('NetresearchTimeTrackerBundle:Activity')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($activity);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }


    /**
     * @param Request $request
     * @return Response
     */
    public function saveTeamAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Team');

        $id         = (int) $request->get('id');
        $name       = $request->get('name');
        $teamLead   = $request->get('lead_user_id') ?
            $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($request->get('lead_user_id'))
            : null;

        if ($id) {
            $team = $repository->find($id);
        } else {
            $team = new Team();
        }

        if ($sameNamedTeam = $repository->findOneByName($name)) {
            if ($team->getId() != $sameNamedTeam->getId()) {
                $response = new Response($this->translate('The team name provided already exists.'));
                $response->setStatusCode(406);
                return $response;
            }
        }

        if (is_null($teamLead)) {
            $response = new Response($this->translate('Please provide a valid user as team leader.'));
            $response->setStatusCode(406);
            return $response;
        }

        try {
            $team
                ->setName($name)
                ->setLeadUser($teamLead);

            $em = $this->getDoctrine()->getManager();
            $em->persist($team);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = array($team->getId(), $team->getName(), ($team->getLeadUser()? $team->getLeadUser()->getId() : ''));

        return new Response(json_encode($data));
    }


    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteTeamAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $team = $doctrine->getRepository('NetresearchTimeTrackerBundle:Team')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($team);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }


    /**
     * @param Request $request
     * @return Response
     */
    public function jiraSyncEntriesAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $doctrine = $this->getDoctrine();

        $users = $doctrine
            ->getRepository('NetresearchTimeTrackerBundle:User')
            ->findAll();

        $ticketSystems = $doctrine
            ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
            ->findAll();

        $data = [];

        /** @var User $user */
        foreach ($users as $user) {
            /** @var TicketSystem $ticketSystem */
            foreach ($ticketSystems as $ticketSystem) {
                try {
                    $jiraOauthApi = new JiraOAuthApi($user, $ticketSystem, $doctrine, $this->container->get('router'));
                    $jiraOauthApi->updateAllEntriesJiraWorkLogs();
                    $data[$ticketSystem->getName() . ' | ' . $user->getUsername()] = 'success';
                } catch (\Exception $e) {
                    $data[$ticketSystem->getName() . ' | ' . $user->getUsername()] = 'error (' . $e->getMessage() . ')';
                }
            }
        }

        return new Response(json_encode($data));
    }


    /**
     * @param Request $request
     * @return Response
     */
    public function getContractsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Repository\ContractRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Contract');

        return new Response(json_encode($repo->getContracts()));
    }


    /**
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function saveContractAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $contractId = (int) $request->get('id');
        $start      = $request->get('start');
        $end        = $request->get('end');
        $hours_0    = str_replace(',', '.', $request->get('hours_0'));
        $hours_1    = str_replace(',', '.', $request->get('hours_1'));
        $hours_2    = str_replace(',', '.', $request->get('hours_2'));
        $hours_3    = str_replace(',', '.', $request->get('hours_3'));
        $hours_4    = str_replace(',', '.', $request->get('hours_4'));
        $hours_5    = str_replace(',', '.', $request->get('hours_5'));
        $hours_6    = str_replace(',', '.', $request->get('hours_6'));
        /** @var User $user */
        $user       = $request->get('user_id') ?
            $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($request->get('user_id'))
            : null;

        /* @var $contractRepository \Netresearch\TimeTrackerBundle\Repository\ContractRepository */
        $contractRepository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Contract');

        if ($contractId) {
            $contract = $contractRepository->find($contractId);
        } else {
            $contract = new Contract();
        }

        if (!$user) {
            $response = new Response($this->translate('Please enter a valid user.'));
            $response->setStatusCode(406);
            return $response;
        }

        $dateStart = \DateTime::createFromFormat('Y-m-d', $start);
        if (!$dateStart) {
            $response = new Response($this->translate('Please enter a valid contract start.'));
            $response->setStatusCode(406);
            return $response;
        }
        $dateStart->setDate($dateStart->format('Y'), $dateStart->format('m'), 1);
        $dateStart->setTime(0, 0, 0);

        $dateEnd = \DateTime::createFromFormat('Y-m-d', $end);
        if ($dateEnd) {
            $dateEnd->setDate($dateEnd->format('Y'), $dateEnd->format('m'), 1);
            $dateEnd->add(new \DateInterval('P1M'));
            $dateEnd->sub(new \DateInterval('P1D'));
            $dateEnd->setTime(23, 59, 59);

            if ($dateEnd < $dateStart) {
                $response = new Response($this->translate('End date has to be greater than the start date.'));
                $response->setStatusCode(406);
                return $response;
            }
        } else {
            $dateEnd = null;
        }

        $contract->setUser($user)
            ->setStart($dateStart)
            ->setEnd($dateEnd)
            ->setHours0($hours_0)
            ->setHours1($hours_1)
            ->setHours2($hours_2)
            ->setHours3($hours_3)
            ->setHours4($hours_4)
            ->setHours5($hours_5)
            ->setHours6($hours_6);

        $em = $this->getDoctrine()->getManager();
        $em->persist($contract);
        $em->flush();

        $data = array($contract->getId());
        return new Response(json_encode($data));
    }


    /**
     * @param Request $request
     * @return Response|Error
     */
    public function deleteContractAction(Request $request)
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $doctrine = $this->getDoctrine();

            $contract = $doctrine->getRepository('NetresearchTimeTrackerBundle:Contract')
                ->find($id);

            $em = $doctrine->getManager();
            $em->remove($contract);
            $em->flush();
        } catch (\Exception $e) {
            $reason = '';
            if (strpos($e->getMessage(), 'Integrity constraint violation') !== false) {
                $reason = $this->translate('Other datasets refer to this one.');
            }
            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new Response(json_encode(array('success' => true)));
    }

}
