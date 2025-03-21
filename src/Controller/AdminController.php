<?php

namespace App\Controller;

use DateTime;
use App\Repository\UserRepository;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Entity\Contract;
use App\Entity\Team;
use App\Helper\JiraOAuthApi;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Project;
use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Preset;
use App\Entity\TicketSystem;
use App\Entity\Activity;
use App\Helper\TimeHelper;
use App\Services\SubticketSyncService;

/**
 * Class AdminController
 * @package App\Controller
 */
class AdminController extends BaseController
{
    /**
     * @param Request $request
     * @return Response
     */
    public function getCustomersAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $repo */
        $repo = $this->doctrineRegistry->getRepository(Customer::class);

        return new JsonResponse($repo->getAllCustomers());
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

        /** @var \App\Repository\UserRepository $repo */
        $repo = $this->doctrineRegistry->getRepository(User::class);

        return new JsonResponse($repo->getAllUsers());
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

        /** @var \App\Repository\TeamRepository $repo */
        $repo = $this->doctrineRegistry->getRepository(Team::class);

        return new JsonResponse($repo->findAll());
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

        /** @var \App\Repository\PresetRepository $repo */
        $repo = $this->doctrineRegistry->getRepository(Preset::class);

        return new JsonResponse($repo->getAllPresets());
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

        /** @var \App\Repository\TicketSystemRepository $repo */
        $repo = $this->doctrineRegistry->getRepository(TicketSystem::class);
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

        return new JsonResponse($ticketSystems);
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

        /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
        $ticketSystemRepo = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystem = $request->get('ticket_system') ? $ticketSystemRepo->find($request->get('ticket_system')) : null;

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->doctrineRegistry->getRepository(User::class);
        $projectLead = $request->get('project_lead') ? $userRepo->find($request->get('project_lead')) : null;
        $technicalLead = $request->get('technical_lead') ? $userRepo->find($request->get('technical_lead')) : null;

        $jiraId       = $request->get('jiraId') ? strtoupper($request->get('jiraId')) : '';
        $jiraTicket   = $request->get('jiraTicket') ? strtoupper($request->get('jiraTicket')) : '';
        $active       = $request->get('active') ? $request->get('active') : 0;
        $global       = $request->get('global') ? $request->get('global') : 0;
        $estimation   = TimeHelper::readable2minutes($request->get('estimation') ? $request->get('estimation') : '0m');
        $billing      = $request->get('billing') ? $request->get('billing') : 0;
        $costCenter   = $request->get('cost_center') ? $request->get('cost_center') : NULL;
        $offer        = $request->get('offer') ? $request->get('offer') : 0;
        $additionalInformationFromExternal = $request->get('additionalInformationFromExternal') ? $request->get('additionalInformationFromExternal') : 0;
        /** @var \App\Repository\ProjectRepository $projectRepository */
        $projectRepository = $this->doctrineRegistry->getRepository(Project::class);
        $internalJiraTicketSystem = (int) $request->get('internalJiraTicketSystem', 0);
        $internalJiraProjectKey   = $request->get('internalJiraProjectKey', 0);

        if ($projectId) {
            $project = $projectRepository->find($projectId);
        } else {
            $project = new Project();

            /** @var Customer $customer */
            $customer = $this->doctrineRegistry->getRepository(Customer::class)
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
            ->setJiraTicket($jiraTicket)
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

        $em = $this->doctrineRegistry->getManager();
        $em->persist($project);
        $em->flush();

        $data = array($project->getId(), $name, $project->getCustomer()->getId(), $jiraId);

        if ($ticketSystem) {
            try {
                $stss = new SubticketSyncService($this->doctrineRegistry, $this->router, $this->container->get('logger'));
                $subtickets = $stss->syncProjectSubtickets($project->getId());
            } catch (\Exception $e) {
                //we do not let it fail because creating a new project
                // would lead to inconsistencies in the frontend
                // ("project with that name exists already")
                $data['message'] = $e->getMessage();
            }
        }

        return new JsonResponse($data);
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
            $doctrine = $this->doctrineRegistry;

            $project = $doctrine->getRepository(Project::class)
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

        return new JsonResponse(array('success' => true));
    }

    /**
     * Update the subtickets for all projects.
     */
    public function syncAllProjectSubticketsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $this->doctrineRegistry->getRepository(Project::class);
        $projects = $projectRepo->createQueryBuilder('p')
            ->where('p.ticketSystem IS NOT NULL')
            ->getQuery()
            ->getResult();

        try {
            $stss = new SubticketSyncService($this->doctrineRegistry, $this->router, $this->container->get('logger'));

            foreach ($projects as $project) {
                $subtickets = $stss->syncProjectSubtickets($project->getId());
            }

            return new JsonResponse(
                [
                    'success' => true
                ]
            );
        } catch (\Exception $e) {
            return new Error($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Fetch subtickets from Jira and update the project record's "subtickets" field.
     *
     * The project lead user's Jira tokens are used for access.
     */
    public function syncProjectSubticketsAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $projectId = (int) $request->get('project');

        try {
            $stss = new SubticketSyncService($this->doctrineRegistry, $this->router, $this->container->get('logger'));
            $subtickets = $stss->syncProjectSubtickets($projectId);
            return new JsonResponse(
                [
                    'success'    => true,
                    'subtickets' => $subtickets
                ]
            );
        } catch (\Exception $e) {
            return new Error($e->getMessage(), $e->getCode());
        }
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

        /** @var \App\Repository\CustomerRepository $customerRepository */
        $customerRepository = $this->doctrineRegistry->getRepository(Customer::class);

        if ($customerId) {
            $customer = $customerRepository->find($customerId);
            if (!$customer) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
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
            if ($team = $this->doctrineRegistry->getRepository(Team::class)->find( (int) $teamId)) {
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

        $em = $this->doctrineRegistry->getManager();
        $em->persist($customer);
        $em->flush();

        $data = array($customer->getId(), $name, $active, $global, $teamIds);

        return new JsonResponse($data);
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
            $doctrine = $this->doctrineRegistry;

            $customer = $doctrine->getRepository(Customer::class)
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

        return new JsonResponse(array('success' => true));
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
        $teamIds  = $request->get('teams')  ? (array) $request->get('teams')  : array();

        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = $this->doctrineRegistry->getRepository(User::class);

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

        if (strlen($abbr) != 3) {
            $response = new Response($this->translate('Please provide a valid user name abbreviation with 3 letters.'));
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
            if ($team = $this->doctrineRegistry->getRepository(Team::class)->find((int)$teamId)) {
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

        $em = $this->doctrineRegistry->getManager();
        $em->persist($user);
        $em->flush();

        $data = array($user->getId(), $name, $abbr, $type);
        return new JsonResponse($data);
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
            $doctrine = $this->doctrineRegistry;

            $user = $doctrine->getRepository(User::class)
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

        return new JsonResponse(array('success' => true));
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
            $doctrine = $this->doctrineRegistry;

            $preset = $doctrine->getRepository(Preset::class)
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

        return new JsonResponse(array('success' => true));
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
        $customer    = $this->doctrineRegistry->getRepository(Customer::class)
            ->find($request->get('customer'));
        $project     = $this->doctrineRegistry->getRepository(Project::class)
            ->find($request->get('project'));
        $activity    = $this->doctrineRegistry->getRepository(Activity::class)
            ->find($request->get('activity'));
        $description = $request->get('description');

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        $repository = $this->doctrineRegistry->getRepository(Preset::class);

        if ($id) {
            $preset = $repository->find($id);
            if (!$preset) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
        } else {
            $preset = new Preset();
        }

        try {
            $preset->setName($name)
                ->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity)
                ->setDescription($description);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($preset);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(403);
            return $response;
        }

        return new JsonResponse($preset->toArray());
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

        /** @var \App\Repository\TicketSystemRepository $repository */
        $repository = $this->doctrineRegistry->getRepository(TicketSystem::class);

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
            if (!$ticketSystem) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
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

            $em = $this->doctrineRegistry->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        return new JsonResponse($ticketSystem->toArray());
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
            $doctrine = $this->doctrineRegistry;

            $ticketSystem = $doctrine->getRepository(TicketSystem::class)
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

        return new JsonResponse(array('success' => true));
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

        /** @var \App\Repository\ActivityRepository $repository */
        $repository = $this->doctrineRegistry->getRepository(Activity::class);

        $id             = (int) $request->get('id');
        $name           = $request->get('name');
        $needsTicket    = (boolean) $request->get('needsTicket');
        $factor         = str_replace(',', '.', $request->get('factor'));

        if ($id) {
            $activity = $repository->find($id);
            if (!$activity) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
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

            $em = $this->doctrineRegistry->getManager();
            $em->persist($activity);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = array($activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor());

        return new JsonResponse($data);
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
            $doctrine = $this->doctrineRegistry;

            $activity = $doctrine->getRepository(Activity::class)
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

        return new JsonResponse(array('success' => true));
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

        /** @var \App\Repository\TeamRepository $repository */
        $repository = $this->doctrineRegistry->getRepository(Team::class);

        $id         = (int) $request->get('id');
        $name       = $request->get('name');
        $teamLead   = $request->get('lead_user_id') ?
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->get('lead_user_id'))
            : null;

        if ($id) {
            $team = $repository->find($id);
            //abort for non existing id
            if (!$team) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
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

            $em = $this->doctrineRegistry->getManager();
            $em->persist($team);
            $em->flush();
        } catch (\Exception $e) {
            $response = new Response($this->translate('Error on save') . ': ' . $e->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = array($team->getId(), $team->getName(), ($team->getLeadUser()? $team->getLeadUser()->getId() : ''));

        return new JsonResponse($data);
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
            $doctrine = $this->doctrineRegistry;

            $team = $doctrine->getRepository(Team::class)
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

        return new JsonResponse(array('success' => true));
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

        $doctrine = $this->doctrineRegistry;

        $users = $doctrine
            ->getRepository(User::class)
            ->findAll();

        $ticketSystems = $doctrine
            ->getRepository(TicketSystem::class)
            ->findAll();

        $data = [];

        /** @var User $user */
        foreach ($users as $user) {
            /** @var TicketSystem $ticketSystem */
            foreach ($ticketSystems as $ticketSystem) {
                try {
                    $jiraOauthApi = new JiraOAuthApi(
                        $user, $ticketSystem, $doctrine, $this->router
                    );
                    $jiraOauthApi->updateAllEntriesJiraWorkLogs();
                    $data[$ticketSystem->getName() . ' | ' . $user->getUsername()] = 'success';
                } catch (\Exception $e) {
                    $data[$ticketSystem->getName() . ' | ' . $user->getUsername()] = 'error (' . $e->getMessage() . ')';
                }
            }
        }

        return new JsonResponse($data);
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

        /** @var \App\Repository\ContractRepository $repo */
        $repo = $this->doctrineRegistry->getRepository(Contract::class);

        return new JsonResponse($repo->getContracts());
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
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->get('user_id'))
            : null;

        /** @var \App\Repository\ContractRepository $contractRepository */
        $contractRepository = $this->doctrineRegistry->getRepository(Contract::class);

        /** @var \App\Entity\Contract $contract */
        if ($contractId) {
            $contract = $contractRepository->find($contractId);
            if (!$contract) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
        } else {
            $contract = new Contract();
        }

        if (!$user) {
            $response = new Response($this->translate('Please enter a valid user.'));
            $response->setStatusCode(406);
            return $response;
        }

        $dateStart = \DateTime::createFromFormat('Y-m-d', $start ?: '');
        if (!$dateStart) {
            $response = new Response($this->translate('Please enter a valid contract start.'));
            $response->setStatusCode(406);
            return $response;
        }
        $dateStart->setDate($dateStart->format('Y'), $dateStart->format('m'), $dateStart->format('d'));
        $dateStart->setTime(0, 0, 0);

        $dateEnd = \DateTime::createFromFormat('Y-m-d', $end ?: '');
        if ($dateEnd) {
            $dateEnd->setDate($dateEnd->format('Y'), $dateEnd->format('m'), $dateEnd->format('d'));
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

        $em = $this->doctrineRegistry->getManager();
        $em->persist($contract);

        // when updating a existing contract don't look for other contracts for the user
        if ($contractId) {
            $em->flush();
            return new JsonResponse(array($contract->getId()));
        }

        // update old contracts,
        $responseMessage = $this->updateOldContractAction($user, $dateStart, $dateEnd);
        if($responseMessage) {
            $response = new Response($responseMessage);
            $response->setStatusCode(406);
            return $response;
        }

        // save new contract
        $em->flush();
        return new JsonResponse(array($contract->getId()));
    }

    /**
     * Look for existing contracts for user and update the latest if open-ended
     * When updating to PHP8 change return type to string|null
     */
    protected function updateOldContractAction(User $user, DateTime $newStartDate, ?DateTime $newEndDate): string
    {
        $em = $this->doctrineRegistry->getManager();
        $contractRepository = $this->doctrineRegistry->getRepository(Contract::class);

        // get existing contracts for the user
        $contractsOld = $contractRepository->findBy(['user' => $user]);

        if (!$contractsOld) {
            return "";
        }

        if ($this->checkOldContractsStartDateOverlap($contractsOld, $newStartDate, $newEndDate)) {
            return $this->translate('There is already an ongoing contract with a start date in the future that overlaps with the new contract.');
        }

        if ($this->checkOldContractsEndDateOverlap($contractsOld, $newStartDate)) {
            return $this->translate('There is already an ongoing contract with a closed end date in the future.');
        }

        // filter to get only open-ended contracts
        $contractsOld = array_filter($contractsOld, fn($n) => ($n->getEnd() == null));
        if (count((array) $contractsOld) > 1) {
            return $this->translate('There is more than one open-ended contract for the user.');
        }

        if(!$contractsOld) {
            return "";
        }

        $contractOld = array_values($contractsOld)[0];

        // alter exisiting contract with open end
        // |--old--(update)
        //      |--new----(|)->
        if ($contractOld->getStart() <= $newStartDate) {
            $oldContractEndDate = clone $newStartDate;
            $contractOld->setEnd($oldContractEndDate->sub(new \DateInterval('P1D')));
            $em->persist((object) $contractOld);
            $em->flush();
        }
        //skip old contract edit for
        // |--new--| |--old--(|)-->
        // and
        // |--old--| |--new--(|)-->
        return "";
    }

    /**
     * look for old contracts that start during the duration of the new contract
     *      |--old----->
     *  |--new--(|)-->
     */
    protected function checkOldContractsStartDateOverlap(array $contracts, DateTime $newStartDate, ?DateTime $newEndDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            $startsAfterOrOnNewStartDate = $contract->getStart() >= $newStartDate;
            $startsBeforeOrOnNewEndDate = ($newEndDate !== null) ? ($contract->getStart() <= $newEndDate) : true;

            if ($startsAfterOrOnNewStartDate && $startsBeforeOrOnNewEndDate) {
                $filteredContracts[] = $contract;
            }
        }
        return (bool) $filteredContracts;
    }

    /** look for contract with ongoing end
     * |--old--|
     *      |--new----->
     */
    protected function checkOldContractsEndDateOverlap(array $contracts, DateTime $newStartDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            $startsBeforeOrOnNewDate = $contract->getStart() <= $newStartDate;
            $endsAfterOrOnNewDate = $contract->getEnd() >= $newStartDate;
            $hasEndDate = $contract->getEnd() !== null;

            if ($startsBeforeOrOnNewDate && $endsAfterOrOnNewDate && $hasEndDate) {
                $filteredContracts[] = $contract;
            }
        }
        return (bool) $filteredContracts;
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
            $doctrine = $this->doctrineRegistry;

            $contract = $doctrine->getRepository(Contract::class)
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

        return new JsonResponse(array('success' => true));
    }

}
