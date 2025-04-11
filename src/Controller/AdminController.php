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
     * Logger property for the controller
     */
    protected $logger;

    /**
     * Sets the logger for this controller
     *
     * @required
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getCustomersAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        return new JsonResponse($objectRepository->getAllCustomers());
    }

    public function getUsersAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        return new JsonResponse($objectRepository->getAllUsers());
    }

    public function getTeamsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        return new JsonResponse($objectRepository->findAll());
    }

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
                $subticketSyncService = new SubticketSyncService($this->doctrineRegistry, $this->router, $this->container->get('logger'));
                $subtickets = $subticketSyncService->syncProjectSubtickets($project->getId());
            } catch (\Exception $e) {
                //we do not let it fail because creating a new project
                // would lead to inconsistencies in the frontend
                // ("project with that name exists already")
                $data['message'] = $e->getMessage();
            }
        }

        return new JsonResponse($data);
    }

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
            $subticketSyncService = new SubticketSyncService($this->doctrineRegistry, $this->router, $this->container->get('logger'));

            foreach ($projects as $project) {
                $subtickets = $subticketSyncService->syncProjectSubtickets($project->getId());
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
    public function syncProjectSubticketsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse|\App\Response\Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $projectId = (int) $request->get('project');

        try {
            $subticketSyncService = new SubticketSyncService($this->doctrineRegistry, $this->router, $this->container->get('logger'));
            $subtickets = $subticketSyncService->syncProjectSubtickets($projectId);
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

    public function saveTeamAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $data = null;
        $id     = (int) $request->get('id');
        $name   = (string) $request->get('name');
        $users  = (array) $request->get('users');

        /** @var \App\Repository\TeamRepository $teamRepository */
        $teamRepository = $this->doctrineRegistry->getRepository(Team::class);

        if ($id !== 0) {
            $team = $teamRepository->find($id);
        } else {
            $team = new Team();
        }

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid team name with at least 3 letters.'));
            $response->setStatusCode(406);
            return $response;
        }

        $sameNamedTeam = $teamRepository->findOneBy(['name' => $name]);
        if ($sameNamedTeam && $team->getId() != $sameNamedTeam->getId()) {
            $response = new Response($this->translate('The team name provided already exists.'));
            $response->setStatusCode(406);
            return $response;
        }

        $em = $this->doctrineRegistry->getManager();

        $team->clearUsers();

        foreach ($users as $userId) {
            $entityRepository = $this->doctrineRegistry->getRepository(User::class);
            $user = $entityRepository->find($userId);
            if ($user) {
                $team->addUser($user);
            }
        }

        $team->setName($name);

        try {
            $em->persist($team);
            $em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(403);
            return $response;
        }

        $data = [];
        $data['id'] = $team->getId();
        $data['name'] = $team->getName();
        $data['users'] = [];
        foreach ($team->getUsers() as $user) {
            $data['users'][] = ['id' => $user->getId(), 'name' => $user->getDisplayName()];
        }

        return new JsonResponse($data);
    }

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

    public function jiraSyncEntriesAction(Request $request): \App\Model\Response|\App\Model\JsonResponse|\App\Response\Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $syncSince = null;
        if (!empty($request->get('sync_since'))) {
            try {
                $syncSince = new \DateTime($request->get('sync_since'));
            } catch (\Exception $e) {
                $this->logger->error('Invalid sync_since: ' . $e->getMessage());
            }
        }

        $jiraFieldId = $request->get('jira_field_id');
        $jiraTicketSystem = $request->get('jira_ticket_system');

        // Check if we want to sync all entries ever or only entries since a certain date
        if ($syncSince === null) {
            // Sync all entries

            $this->logger->info('Starting FULL sync of time entries with Jira');

            // Sync all time entries with Jira
            $syncPrepMsg = $this->translator->trans('Start synchronization of all time entries.');
        } else {
            $this->logger->info('Starting sync of time entries with Jira since ' . $syncSince->format('Y-m-d H:i:s'));

            // Sync time entries since $syncSince with Jira
            $syncPrepMsg = $this->translator->trans('Start synchronization of time entries since %date%.', [
                '%date%' => $syncSince->format('Y-m-d H:i:s'),
            ]);
        }

        try {
            /** @var JiraOAuthApi */
            $jiraApi = $this->get('jira_oauth_api');
        } catch (\Throwable $t) {
            // Translation not required, is only visible for PL
            return new Error('Cannot authenticate. Maybe broken private key?', 422, 'javascript:history.back();');
        }

        $entriesPerProject = [];

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $this->doctrineRegistry->getRepository(Project::class);
        $allProjects = $projectRepo->findAll();

        /** @var \App\Repository\EntryRepository $entriesRepo */
        $entriesRepo = $this->doctrineRegistry->getRepository(\App\Entity\Entry::class);

        /** @var Project $project */
        foreach ($allProjects as $project) {
            if (!$project->getTicketSystem()) {
                continue;
            }

            $entries = $entriesRepo->getEntriesWithTicketsForProject($project->getId());
            $entriesPerProject[$project->getId()] = ['project' => $project, 'entries' => $entries];
        }

        $data = [
            'status' => 'success',
            'details' => $entriesPerProject,
            'message' => $syncPrepMsg,
        ];
        return new JsonResponse($data);
    }
}
