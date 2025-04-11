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

    public function getPresetsAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\PresetRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        return new JsonResponse($objectRepository->getAllPresets());
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
     */
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

    public function jiraSyncEntriesAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
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
