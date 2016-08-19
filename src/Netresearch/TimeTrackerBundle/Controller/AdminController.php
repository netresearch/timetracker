<?php

namespace Netresearch\TimeTrackerBundle\Controller;

//use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Netresearch\TimeTrackerBundle\Entity\Team;
use Symfony\Component\HttpFoundation\Request;
use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\Customer;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\Preset;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\Activity;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;

class AdminController extends BaseController
{
    public function getAllProjectsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $data = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project')->findAll();

        return new Response(json_encode($data));
    }

    public function getCustomersAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Entity\CustomerRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Customer');

        return new Response(json_encode($repo->getAllCustomers()));
    }

    public function getUsersAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Entity\UserRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:User');

        return new Response(json_encode($repo->getAllUsers()));
    }

    public function getTeamsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Entity\TeamRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Team');

        return new Response(json_encode($repo->findAll()));
    }

    public function getPresetsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Entity\PresetRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Preset');

        return new Response(json_encode($repo->getAllPresets()));
    }

    public function getTicketSystemsAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /* @var $repo \Netresearch\TimeTrackerBundle\Entity\TicketSystemRepository */
        $repo = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:TicketSystem');

        return new Response(json_encode($repo->getAllTicketSystems()));
    }

    public function saveProjectAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $projectId  = (int) $request->get('id');
        $name       = $request->get('name');

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
        /* @var $projectRepository \Netresearch\TimeTrackerBundle\Entity\ProjectRepository */
        $projectRepository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Project');
        $internalJiraTicketSystem = (int) $request->get('internalJiraTicketSystem', 0);
        $internalJiraProjectKey   = (int) $request->get('internalJiraProjectKey', 0);

        if ($projectId) {
            $project = $projectRepository->find($projectId);
        } else {
            $project = new Project();

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

    public function saveCustomerAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $customerId  = (int) $request->get('id');
        $name       = $request->get('name');
        $active     = $request->get('active') ? $request->get('active') : 0;
        $global     = $request->get('global') ? $request->get('global') : 0;
        $teamIds    = $request->get('teams')  ? $request->get('teams')  : array();

        /* @var $customerRepository \Netresearch\TimeTrackerBundle\Entity\CustomerRepository */
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

    public function saveUserAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId   = (int) $request->get('id');
        $name     = $request->get('username');
        $abbr     = $request->get('abbr');
        $type     = $request->get('type');
        $locale   = $request->get('locale');
        $teamIds  = $request->get('teams')  ? $request->get('teams')  : array();

        /* @var $userRepository \Netresearch\TimeTrackerBundle\Entity\UserRepository */
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

    public function deletePresetAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id             = (int) $request->get('id');
        $doctrine = $this->getDoctrine();

        $entry = $doctrine->getRepository('NetresearchTimeTrackerBundle:Preset')
                ->find($id);

        $em = $doctrine->getManager();
        $em->remove($entry);
        $em->flush();

        return new Response(json_encode(array('success' => true)));
    }

    public function savePresetAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id             = (int) $request->get('id');
        $name           = $request->get('name');
        $customer       = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Customer')
                        ->find($request->get('customer'));
        $project        = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Project')
                        ->find($request->get('project'));
        $activity       = $this->getDoctrine()
                        ->getRepository('NetresearchTimeTrackerBundle:Activity')
                        ->find($request->get('activity'));
        $description    = $request->get('description');

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



    public function saveTicketSystemAction(Request $request)
    {
        if (false == $this->_isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:TicketSystem');

        $id             = (int) $request->get('id');
        $name           = $request->get('name');
        $type           = $request->get('type');
        $bookTime       = $request->get('bookTime');
        $url            = $request->get('url');
        $ticketUrl      = $request->get('ticketUrl');
        $login          = $request->get('login');
        $password       = $request->get('password');
        $publicKey      = $request->get('publicKey');
        $privateKey     = $request->get('privateKey');

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
                ->setPrivateKey($privateKey);

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



    public function saveActivityAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl($request)) {
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



    public function saveTeamAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false == $this->_isPl($request)) {
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

}
