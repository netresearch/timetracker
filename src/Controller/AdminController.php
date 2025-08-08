<?php

namespace App\Controller;

use DateTime;
use App\Repository\UserRepository;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Entity\Contract;
use App\Entity\Team;
use App\Helper\JiraOAuthApi;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Project;
use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Preset;
use App\Entity\TicketSystem;
use App\Entity\Activity;
use App\Helper\TimeHelper;
use App\Service\SubticketSyncService;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AdminController
 * @package App\Controller
 */
class AdminController extends BaseController
{
    private SubticketSyncService $subticketSyncService;
    private JiraOAuthApiFactory $jiraApiFactory;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraApiFactory): void
    {
        $this->jiraApiFactory = $jiraApiFactory;
    }
    #[Route('/getAllCustomers', name: '_getAllCustomers_attr', methods: ['GET'])]
    public function getCustomersAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        return new JsonResponse($objectRepository->getAllCustomers());
    }

    #[Route('/getAllUsers', name: '_getAllUsers_attr', methods: ['GET'])]
    public function getUsersAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        return new JsonResponse($objectRepository->getAllUsers());
    }

    #[Route('/getAllTeams', name: '_getAllTeams_attr', methods: ['GET'])]
    public function getTeamsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        return new JsonResponse($objectRepository->findAll());
    }

    #[Route('/getAllPresets', name: '_getAllPresets_attr', methods: ['GET'])]
    public function getPresetsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\PresetRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        return new JsonResponse($objectRepository->getAllPresets());
    }

    /**
     * @throws \ReflectionException
     */
    #[Route('/getTicketSystems', name: '_getTicketSystems_attr', methods: ['GET'])]
    public function getTicketSystemsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystems = $objectRepository->getAllTicketSystems();

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

    #[Route('/project/save', name: 'saveProject_attr', methods: ['POST'])]
    public function saveProjectAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $projectId  = (int) $request->get('id');
        $name       = $request->get('name');

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystem = $request->get('ticket_system') ? $objectRepository->find($request->get('ticket_system')) : null;

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->doctrineRegistry->getRepository(User::class);
        $projectLead = $request->get('project_lead') ? $userRepo->find($request->get('project_lead')) : null;
        $technicalLead = $request->get('technical_lead') ? $userRepo->find($request->get('technical_lead')) : null;

        $jiraId       = $request->get('jiraId') ? strtoupper((string) $request->get('jiraId')) : '';
        $jiraTicket   = $request->get('jiraTicket') ? strtoupper((string) $request->get('jiraTicket')) : '';
        $active       = $request->get('active') ?: 0;
        $global       = $request->get('global') ?: 0;
        $estimation   = TimeHelper::readable2minutes($request->get('estimation') ?: '0m');
        $billing      = $request->get('billing') ?: 0;
        $costCenter   = $request->get('cost_center') ?: null;
        $offer        = $request->get('offer') ?: 0;
        $additionalInformationFromExternal = $request->get('additionalInformationFromExternal') ?: 0;
        /** @var \App\Repository\ProjectRepository $projectRepository */
        $projectRepository = $this->doctrineRegistry->getRepository(Project::class);
        $internalJiraTicketSystem = (int) $request->get('internalJiraTicketSystem', 0);
        $internalJiraProjectKey   = $request->get('internalJiraProjectKey', 0);

        if ($projectId !== 0) {
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

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid project name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        $sameNamedProject = $projectRepository->findOneBy(
            ['name' => $name, 'customer' => $project->getCustomer()->getId()]
        );
        if ($sameNamedProject && $project->getId() != $sameNamedProject->getId()) {
            $response = new Response($this->translate('The project name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        if ((1 < strlen($jiraId)) && ($project->getJiraId() !== $jiraId)) {
            $search = ['jiraId' => $jiraId];
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

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($project);
        $objectManager->flush();

        $data = [$project->getId(), $name, $project->getCustomer()->getId(), $jiraId];

        if ($ticketSystem) {
            try {
                $subtickets = $this->subticketSyncService->syncProjectSubtickets($project->getId());
            } catch (\Exception $e) {
                //we do not let it fail because creating a new project
                // would lead to inconsistencies in the frontend
                // ("project with that name exists already")
                $data['message'] = $e->getMessage();
            }
        }

        return new JsonResponse($data);
    }

    #[Route('/project/delete', name: 'deleteProject_attr', methods: ['POST'])]
    public function deleteProjectAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Update the subtickets for all projects.
     */
    #[Route('/projects/syncsubtickets', name: 'syncAllProjectSubtickets_attr', methods: ['GET'])]
    public function syncAllProjectSubticketsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse|\App\Response\Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Project::class);
        $projects = $objectRepository->createQueryBuilder('p')
            ->where('p.ticketSystem IS NOT NULL')
            ->getQuery()
            ->getResult();

        try {
            foreach ($projects as $project) {
                $subtickets = $this->subticketSyncService->syncProjectSubtickets($project->getId());
            }

            return new JsonResponse(
                [
                    'success' => true
                ]
            );
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * Fetch subtickets from Jira and update the project record's "subtickets" field.
     *
     * The project lead user's Jira tokens are used for access.
     */
    #[Route('/projects/{project}/syncsubtickets', name: 'syncProjectSubtickets_attr', methods: ['GET'])]
    public function syncProjectSubticketsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse|\App\Response\Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $projectId = (int) $request->get('project');

        try {
            $subtickets = $this->subticketSyncService->syncProjectSubtickets($projectId);
            return new JsonResponse(
                [
                    'success'    => true,
                    'subtickets' => $subtickets
                ]
            );
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @return Response
     */
    #[Route('/customer/save', name: 'saveCustomer_attr', methods: ['POST'])]
    public function saveCustomerAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $customerId  = (int) $request->get('id');
        $name       = $request->get('name');
        $active     = $request->get('active') ?: 0;
        $global     = $request->get('global') ?: 0;
        $teamIds    = $request->get('teams') ?: [];

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        if ($customerId !== 0) {
            $customer = $objectRepository->find($customerId);
            if (!$customer) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
        } else {
            $customer = new Customer();
        }

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid customer name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if (($sameNamedCustomer = $objectRepository->findOneByName($name)) && $customer->getId() != $sameNamedCustomer->getId()) {
            $response = new Response($this->translate('The customer name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        $customer->setName($name)->setActive($active)->setGlobal($global);

        $customer->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            if ($team = $this->doctrineRegistry->getRepository(Team::class)->find((int) $teamId)) {
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

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($customer);
        $objectManager->flush();

        $data = [$customer->getId(), $name, $active, $global, $teamIds];

        return new JsonResponse($data);
    }

    #[Route('/customer/delete', name: 'deleteCustomer_attr', methods: ['POST'])]
    public function deleteCustomerAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/user/save', name: 'saveUser_attr', methods: ['POST'])]
    public function saveUserAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId   = (int) $request->get('id');
        $name     = $request->get('username');
        $abbr     = $request->get('abbr');
        $type     = $request->get('type');
        $locale   = $request->get('locale');
        $teamIds  = $request->get('teams') ? (array) $request->get('teams') : [];

        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        $user = $userId !== 0 ? $objectRepository->find($userId) : new User();

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid user name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if (strlen((string) $abbr) != 3) {
            $response = new Response($this->translate('Please provide a valid user name abbreviation with 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if (($sameNamedUser = $objectRepository->findOneByUsername($name)) && $user->getId() != $sameNamedUser->getId()) {
            $response = new Response($this->translate('The user name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        if (($sameAbbrUser = $objectRepository->findOneByAbbr($abbr)) && $user->getId() != $sameAbbrUser->getId()) {
            $response = new Response($this->translate('The user name abreviation provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        $user->setUsername($name)
            ->setAbbr($abbr)
            ->setLocale($locale)
            ->setType($type);

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

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        $data = [$user->getId(), $name, $abbr, $type];
        return new JsonResponse($data);
    }

    #[Route('/user/delete', name: 'deleteUser_attr', methods: ['POST'])]
    public function deleteUserAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/preset/delete', name: 'deletePreset_attr', methods: ['POST'])]
    public function deletePresetAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @return Response
     */
    #[Route('/preset/save', name: 'savePreset_attr', methods: ['POST'])]
    public function savePresetAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        if ($id !== 0) {
            $preset = $objectRepository->find($id);
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
        } catch (\Exception) {
            $response = new Response($this->translate('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(403);
            return $response;
        }

        return new JsonResponse($preset->toArray());
    }


    /**
     * @return Response
     * @throws \ReflectionException
     */
    #[Route('/ticketsystem/save', name: 'saveTicketSystem_attr', methods: ['POST'])]
    public function saveTicketSystemAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);

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

        if ($id !== 0) {
            $ticketSystem = $objectRepository->find($id);
            if (!$ticketSystem) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
        } else {
            $ticketSystem = new TicketSystem();
        }

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid ticket system name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        if (($sameNamedSystem = $objectRepository->findOneByName($name)) && $ticketSystem->getId() != $sameNamedSystem->getId()) {
            $response = new Response($this->translate('The ticket system name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        try {
            $ticketSystem
                ->setName($name)
                ->setType($type)
                ->setBookTime((bool) $bookTime)
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
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        return new JsonResponse($ticketSystem->toArray());
    }


    #[Route('/ticketsystem/delete', name: 'deleteTicketSystem_attr', methods: ['POST'])]
    public function deleteTicketSystemAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }


    /**
     * @return Response
     */
    #[Route('/activity/save', name: 'saveActivity_attr', methods: ['POST'])]
    public function saveActivityAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Activity::class);

        $id             = (int) $request->get('id');
        $name           = $request->get('name');
        $needsTicket    = (bool) $request->get('needsTicket');
        $factor         = str_replace(',', '.', $request->get('factor'));

        if ($id !== 0) {
            $activity = $objectRepository->find($id);
            if (!$activity) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
        } else {
            $activity = new Activity();
        }

        if (($sameNamedActivity = $objectRepository->findOneByName($name)) && $activity->getId() != $sameNamedActivity->getId()) {
            $response = new Response($this->translate('The activity name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        try {
            $activity
                ->setName($name)
                ->setNeedsTicket($needsTicket)
                ->setFactor($factor);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($activity);
            $em->flush();
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = [$activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor()];

        return new JsonResponse($data);
    }


    #[Route('/activity/delete', name: 'deleteActivity_attr', methods: ['POST'])]
    public function deleteActivityAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }


    /**
     * @return Response
     */
    #[Route('/team/save', name: 'saveTeam_attr', methods: ['POST'])]
    public function saveTeamAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        $id         = (int) $request->get('id');
        $name       = $request->get('name');
        $teamLead   = $request->get('lead_user_id') ?
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->get('lead_user_id'))
            : null;

        if ($id !== 0) {
            $team = $objectRepository->find($id);
            //abort for non existing id
            if (!$team) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }
        } else {
            $team = new Team();
        }

        if (($sameNamedTeam = $objectRepository->findOneByName($name)) && $team->getId() != $sameNamedTeam->getId()) {
            $response = new Response($this->translate('The team name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
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
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = [$team->getId(), $team->getName(), ($team->getLeadUser() ? $team->getLeadUser()->getId() : '')];

        return new JsonResponse($data);
    }


    #[Route('/team/delete', name: 'deleteTeam_attr', methods: ['POST'])]
    public function deleteTeamAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }


    #[Route('/syncentries/jira', name: 'syncEntriesToJira_attr', methods: ['GET'])]
    public function jiraSyncEntriesAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
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
                    $jiraOauthApi = $this->jiraApiFactory->create($user, $ticketSystem);
                    $jiraOauthApi->updateAllEntriesJiraWorkLogs();
                    $data[$ticketSystem->getName() . ' | ' . $user->getUsername()] = 'success';
                } catch (\Exception $e) {
                    $data[$ticketSystem->getName() . ' | ' . $user->getUsername()] = 'error (' . $e->getMessage() . ')';
                }
            }
        }

        return new JsonResponse($data);
    }


    #[Route('/getContracts', name: '_getContracts_attr', methods: ['GET'])]
    public function getContractsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        return new JsonResponse($objectRepository->getContracts());
    }


    /**
     * @return Response
     * @throws \Exception
     */
    #[Route('/contract/save', name: 'saveContract_attr', methods: ['POST'])]
    public function saveContractAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

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

        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        /** @var \App\Entity\Contract $contract */
        if ($contractId !== 0) {
            $contract = $objectRepository->find($contractId);
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

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($contract);

        // when updating a existing contract don't look for other contracts for the user
        if ($contractId !== 0) {
            $objectManager->flush();
            return new JsonResponse([$contract->getId()]);
        }

        // update old contracts,
        $responseMessage = $this->updateOldContract($user, $dateStart, $dateEnd);
        if ($responseMessage !== '' && $responseMessage !== '0') {
            $response = new Response($responseMessage);
            $response->setStatusCode(406);
            return $response;
        }

        $objectManager->flush();
        return new JsonResponse([$contract->getId()]);
    }

    /**
     * Look for existing contracts for user and update the latest if open-ended
     * When updating to PHP8 change return type to string|null
     */
    protected function updateOldContract(User $user, DateTime $newStartDate, ?DateTime $newEndDate): string
    {
        $objectManager = $this->doctrineRegistry->getManager();
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        // get existing contracts for the user
        $contractsOld = $objectRepository->findBy(['user' => $user]);

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
        $contractsOld = array_filter($contractsOld, fn ($n): bool => ($n->getEnd() == null));
        if (count($contractsOld) > 1) {
            return $this->translate('There is more than one open-ended contract for the user.');
        }

        if ($contractsOld === []) {
            return "";
        }

        $contractOld = array_values($contractsOld)[0];

        // alter exisiting contract with open end
        // |--old--(update)
        //      |--new----(|)->
        if ($contractOld->getStart() <= $newStartDate) {
            $oldContractEndDate = clone $newStartDate;
            $contractOld->setEnd($oldContractEndDate->sub(new \DateInterval('P1D')));
            $objectManager->persist($contractOld);
            $objectManager->flush();
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
            $startsBeforeOrOnNewEndDate = ($newEndDate instanceof \DateTime) ? ($contract->getStart() <= $newEndDate) : true;

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

    #[Route('/contract/delete', name: 'deleteContract_attr', methods: ['POST'])]
    public function deleteContractAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
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
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }

        return new JsonResponse(['success' => true]);
    }
}
