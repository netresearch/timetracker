<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Helper\TimeHelper;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\UserRepository;
use App\Response\Error;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\SubticketSyncService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class AdminController.
 */
class AdminController extends BaseController
{
    private SubticketSyncService $subticketSyncService;

    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    /**
     * @codeCoverageIgnore
     */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }

    /**
     * @codeCoverageIgnore
     */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllCustomers', name: '_getAllCustomers_attr', methods: ['GET'])]
    public function getCustomers(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        return new JsonResponse($objectRepository->getAllCustomers());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllUsers', name: '_getAllUsers_attr', methods: ['GET'])]
    public function getUsers(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        return new JsonResponse($objectRepository->getAllUsers());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllTeams', name: '_getAllTeams_attr', methods: ['GET'])]
    public function getTeams(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        return new JsonResponse($objectRepository->getAllTeamsAsArray());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllPresets', name: '_getAllPresets_attr', methods: ['GET'])]
    public function getPresets(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\PresetRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        return new JsonResponse($objectRepository->getAllPresets());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTicketSystems', name: '_getTicketSystems_attr', methods: ['GET'])]
    public function getTicketSystems(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystems = $objectRepository->getAllTicketSystems();

        if (false === $this->isPl($request)) {
            $c = count($ticketSystems);
            for ($i = 0; $i < $c; ++$i) {
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

    #[\Symfony\Component\Routing\Attribute\Route(path: '/project/save', name: 'saveProject_attr', methods: ['POST'])]
    public function saveProject(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $projectId = (int) $request->request->get('id');
        $name = $request->request->get('name');

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystem = $request->request->get('ticket_system') ? $objectRepository->find($request->request->get('ticket_system')) : null;

        /** @var UserRepository $userRepo */
        $userRepo = $this->doctrineRegistry->getRepository(User::class);
        $projectLead = $request->request->get('project_lead') ? $userRepo->find($request->request->get('project_lead')) : null;
        if (null !== $projectLead && !$projectLead instanceof User) {
            $projectLead = null;
        }
        $technicalLead = $request->request->get('technical_lead') ? $userRepo->find($request->request->get('technical_lead')) : null;
        if (null !== $technicalLead && !$technicalLead instanceof User) {
            $technicalLead = null;
        }

        $jiraId = $request->request->get('jiraId') ? strtoupper((string) $request->request->get('jiraId')) : '';
        $jiraTicket = $request->request->get('jiraTicket') ? strtoupper((string) $request->request->get('jiraTicket')) : '';
        $active = (bool) ($request->request->get('active') ?: 0);
        $global = (bool) ($request->request->get('global') ?: 0);
        $estimation = TimeHelper::readable2minutes($request->request->get('estimation') ?: '0m');
        $billing = $request->request->get('billing') ?: 0;
        $costCenter = $request->request->get('cost_center') ?: null;
        $offer = $request->request->get('offer') ?: 0;
        $additionalInformationFromExternal = (bool) ($request->request->get('additionalInformationFromExternal') ?: 0);
        /** @var \App\Repository\ProjectRepository $projectRepository */
        $projectRepository = $this->doctrineRegistry->getRepository(Project::class);
        $internalJiraTicketSystem = $request->request->get('internalJiraTicketSystem');
        if ('' === $internalJiraTicketSystem || null === $internalJiraTicketSystem) {
            $internalJiraTicketSystem = null;
        } else {
            $internalJiraTicketSystem = (string) $internalJiraTicketSystem;
        }

        $internalJiraProjectKey = (string) $request->request->get('internalJiraProjectKey', '');

        if (0 !== $projectId) {
            $project = $projectRepository->find($projectId);
            if (!$project instanceof Project) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $project = new Project();

            /** @var Customer $customer */
            $customer = $this->doctrineRegistry->getRepository(Customer::class)
                ->find($request->request->get('customer'));
            if (!$customer instanceof Customer) {
                $response = new Response($this->translate('Please choose a customer.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }

            $project->setCustomer($customer);
        }

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid project name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $projectCustomer = $project->getCustomer();
        if (!$projectCustomer instanceof Customer) {
            $response = new Response($this->translate('Please choose a customer.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $sameNamedProject = $projectRepository->findOneBy(
            ['name' => $name, 'customer' => $projectCustomer->getId()]
        );
        if ($sameNamedProject instanceof Project && $project->getId() != $sameNamedProject->getId()) {
            $response = new Response($this->translate('The project name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (1 < strlen($jiraId) && $project->getJiraId() !== $jiraId && $ticketSystem) {
            $search['ticketSystem'] = $ticketSystem;
        }

        if (strlen($jiraId) && false == $projectRepository->isValidJiraPrefix($jiraId)) {
            $response = new Response($this->translate('Please provide a valid ticket prefix with only capital letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $project
            ->setName($name)
            ->setTicketSystem($ticketSystem instanceof TicketSystem ? $ticketSystem : $project->getTicketSystem())
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

        $data = [$project->getId(), $name, $projectCustomer->getId(), $jiraId];

        if ($ticketSystem) {
            try {
                $this->subticketSyncService->syncProjectSubtickets($project->getId());
            } catch (\Exception $e) {
                // we do not let it fail because creating a new project
                // would lead to inconsistencies in the frontend
                // ("project with that name exists already")
                $data['message'] = $e->getMessage();
            }
        }

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/project/delete', name: 'deleteProject_attr', methods: ['POST'])]
    public function deleteProject(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $project = $doctrine->getRepository(Project::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($project) {
                $em->remove($project);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/projects/syncsubtickets', name: 'syncAllProjectSubtickets_attr', methods: ['GET'])]
    public function syncAllProjectSubtickets(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Project::class);
        /** @var array<int, Project> $projects */
        $projects = $objectRepository->createQueryBuilder('p')
            ->where('p.ticketSystem IS NOT NULL')
            ->getQuery()
            ->getResult();

        try {
            foreach ($projects as $project) {
                if (!$project instanceof Project) {
                    continue;
                }
                $this->subticketSyncService->syncProjectSubtickets($project->getId());
            }

            return new JsonResponse(
                [
                    'success' => true,
                ]
            );
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), (int) ($exception->getCode() ?: 500));
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/projects/{project}/syncsubtickets', name: 'syncProjectSubtickets_attr', methods: ['GET'])]
    public function syncProjectSubtickets(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $projectId = (int) $request->query->get('project');

        try {
            $subtickets = $this->subticketSyncService->syncProjectSubtickets($projectId);

            return new JsonResponse(
                [
                    'success' => true,
                    'subtickets' => $subtickets,
                ]
            );
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), (int) ($exception->getCode() ?: 500));
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/customer/save', name: 'saveCustomer_attr', methods: ['POST'])]
    public function saveCustomer(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $customerId = (int) $request->request->get('id');
        $name = $request->request->get('name');
        $active = $request->request->get('active') ?: 0;
        $global = $request->request->get('global') ?: 0;
        $teamIds = $request->request->get('teams') ?: [];

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        if (0 !== $customerId) {
            $customer = $objectRepository->find($customerId);
            if (!$customer) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
            if (!$customer instanceof Customer) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $customer = new Customer();
        }

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid customer name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (($sameNamedCustomer = $objectRepository->findOneByName($name)) && $customer instanceof Customer && $sameNamedCustomer instanceof Customer && $customer->getId() != $sameNamedCustomer->getId()) {
            $response = new Response($this->translate('The customer name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $customer->setName($name)->setActive($active)->setGlobal($global);

        $customer->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            $team = $this->doctrineRegistry->getRepository(Team::class)->find((int) $teamId);
            if ($team instanceof Team) {
                $customer->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if ($customer instanceof Customer && 0 == $customer->getTeams()->count() && false == $global) {
            $response = new Response($this->translate('Every customer must belong to at least one team if it is not global.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($customer);
        $objectManager->flush();

        $data = [$customer->getId(), $name, $active, $global, $teamIds];

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/customer/delete', name: 'deleteCustomer_attr', methods: ['POST'])]
    public function deleteCustomer(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $customer = $doctrine->getRepository(Customer::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($customer) {
                $em->remove($customer);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/user/save', name: 'saveUser_attr', methods: ['POST'])]
    public function saveUser(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId = (int) $request->request->get('id');
        $name = $request->request->get('username');
        $abbr = $request->request->get('abbr');
        $type = $request->request->get('type');
        $locale = $request->request->get('locale');
        $teamIds = $request->request->get('teams') ? (array) $request->request->get('teams') : [];

        /** @var UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        $user = 0 !== $userId ? $objectRepository->find($userId) : new User();
        if (!$user instanceof User) {
            return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid user name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (3 != strlen((string) $abbr)) {
            $response = new Response($this->translate('Please provide a valid user name abbreviation with 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (($sameNamedUser = $objectRepository->findOneByUsername($name)) && $user instanceof User && $sameNamedUser instanceof User && $user->getId() != $sameNamedUser->getId()) {
            $response = new Response($this->translate('The user name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (($sameAbbrUser = $objectRepository->findOneByAbbr($abbr)) && $user instanceof User && $sameAbbrUser instanceof User && $user->getId() != $sameAbbrUser->getId()) {
            $response = new Response($this->translate('The user name abreviation provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        // $user is ensured to be a User above

        $user->setUsername($name)
            ->setAbbr($abbr)
            ->setLocale($locale)
            ->setType($type);

        $user->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            $team = $this->doctrineRegistry->getRepository(Team::class)->find((int) $teamId);
            if ($team instanceof Team) {
                $user->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (0 == $user->getTeams()->count()) {
            $response = new Response($this->translate('Every user must belong to at least one team'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        $data = [$user->getId(), $name, $abbr, $type];

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/user/delete', name: 'deleteUser_attr', methods: ['POST'])]
    public function deleteUser(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $user = $doctrine->getRepository(User::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($user) {
                $em->remove($user);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/preset/delete', name: 'deletePreset_attr', methods: ['POST'])]
    public function deletePreset(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $preset = $doctrine->getRepository(Preset::class)
                    ->find($id);

            $em = $doctrine->getManager();
            if ($preset) {
                $em->remove($preset);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/preset/save', name: 'savePreset_attr', methods: ['POST'])]
    public function savePreset(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $id = (int) $request->request->get('id');
        $name = $request->request->get('name');
        $customer = $this->doctrineRegistry->getRepository(Customer::class)
            ->find($request->request->get('customer'));
        $project = $this->doctrineRegistry->getRepository(Project::class)
            ->find($request->request->get('project'));
        $activity = $this->doctrineRegistry->getRepository(Activity::class)
            ->find($request->request->get('activity'));
        $description = $request->request->get('description');

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        if (0 !== $id) {
            $preset = $objectRepository->find($id);
            if (!$preset) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
            if (!$preset instanceof Preset) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $preset = new Preset();
        }

        try {
            if (!$customer instanceof Customer || !$project instanceof Project || !$activity instanceof Activity) {
                throw new \Exception('Please choose a customer, a project and an activity.');
            }

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
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        return new JsonResponse($preset->toArray());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/ticketsystem/save', name: 'saveTicketSystem_attr', methods: ['POST'])]
    public function saveTicketSystem(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);

        $id = (int) $request->request->get('id');
        $name = $request->request->get('name');
        $type = $request->request->get('type');
        $bookTime = $request->request->get('bookTime');
        $url = $request->request->get('url');
        $login = $request->request->get('login');
        $password = $request->request->get('password');
        $publicKey = $request->request->get('publicKey');
        $privateKey = $request->request->get('privateKey');
        $ticketUrl = $request->request->get('ticketUrl');
        $oauthConsumerKey = $request->request->get('oauthConsumerKey');
        $oauthConsumerSecret = $request->request->get('oauthConsumerSecret');

        if (0 !== $id) {
            $ticketSystem = $objectRepository->find($id);
            if (!$ticketSystem) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
            if (!$ticketSystem instanceof TicketSystem) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $ticketSystem = new TicketSystem();
        }

        if (strlen((string) $name) < 3) {
            $response = new Response($this->translate('Please provide a valid ticket system name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $sameNamedSystem = $objectRepository->findOneByName($name);
        if ($sameNamedSystem instanceof TicketSystem && $ticketSystem->getId() != $sameNamedSystem->getId()) {
            $response = new Response($this->translate('The ticket system name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            if ($ticketSystem instanceof TicketSystem) {
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
            }

            $em = $this->doctrineRegistry->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        return new JsonResponse($ticketSystem->toArray());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/ticketsystem/delete', name: 'deleteTicketSystem_attr', methods: ['POST'])]
    public function deleteTicketSystem(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $ticketSystem = $doctrine->getRepository(TicketSystem::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($ticketSystem) {
                $em->remove($ticketSystem);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/activity/save', name: 'saveActivity_attr', methods: ['POST'])]
    public function saveActivity(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Activity::class);

        $id = (int) $request->request->get('id');
        $name = $request->request->get('name');
        $needsTicket = (bool) $request->request->get('needsTicket');
        $factorRaw = $request->request->get('factor');
        $factor = (float) str_replace(',', '.', (string) ($factorRaw ?? '0'));

        if (0 !== $id) {
            $activity = $objectRepository->find($id);
            if (!$activity) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
            if (!$activity instanceof Activity) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $activity = new Activity();
        }

        $sameNamedActivity = $objectRepository->findOneByName($name);
        if ($sameNamedActivity instanceof Activity && $activity->getId() != $sameNamedActivity->getId()) {
            $response = new Response($this->translate('The activity name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

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
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $data = [$activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor()];

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/activity/delete', name: 'deleteActivity_attr', methods: ['POST'])]
    public function deleteActivity(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $activity = $doctrine->getRepository(Activity::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($activity) {
                $em->remove($activity);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/team/save', name: 'saveTeam_attr', methods: ['POST'])]
    public function saveTeam(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        $id = (int) $request->request->get('id');
        $name = $request->request->get('name');
        $teamLead = $request->request->get('lead_user_id') ?
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->request->get('lead_user_id'))
            : null;

        if (0 !== $id) {
            $team = $objectRepository->find($id);
            // abort for non existing id
            if (!$team) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
            if (!$team instanceof Team) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $team = new Team();
        }

        $sameNamedTeam = $objectRepository->findOneByName($name);
        if ($sameNamedTeam instanceof Team && $team->getId() != $sameNamedTeam->getId()) {
            $response = new Response($this->translate('The team name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (!$teamLead instanceof User) {
            $response = new Response($this->translate('Please provide a valid user as team leader.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

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
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $data = [$team->getId(), $team->getName(), $team->getLeadUser() ? $team->getLeadUser()->getId() : ''];

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/team/delete', name: 'deleteTeam_attr', methods: ['POST'])]
    public function deleteTeam(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $team = $doctrine->getRepository(Team::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($team) {
                $em->remove($team);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/syncentries/jira', name: 'syncEntriesToJira_attr', methods: ['GET'])]
    public function jiraSyncEntries(Request $request): Response|JsonResponse
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

        /** @var array<int, TicketSystem> $ticketSystems */
        $ticketSystems = $doctrine
            ->getRepository(TicketSystem::class)
            ->findAll();

        $data = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }
            foreach ($ticketSystems as $ticketSystem) {
                if (!$ticketSystem instanceof TicketSystem) {
                    continue;
                }
                try {
                    $jiraOauthApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
                    $jiraOauthApi->updateAllEntriesJiraWorkLogs();
                    $data[$ticketSystem->getName().' | '.$user->getUsername()] = 'success';
                } catch (\Exception $e) {
                    $data[$ticketSystem->getName().' | '.$user->getUsername()] = 'error ('.$e->getMessage().')';
                }
            }
        }

        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getContracts', name: '_getContracts_attr', methods: ['GET'])]
    public function getContracts(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        return new JsonResponse($objectRepository->getContracts());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/contract/save', name: 'saveContract_attr', methods: ['POST'])]
    public function saveContract(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $contractId = (int) $request->request->get('id');
        $start = $request->request->get('start');
        $end = $request->request->get('end');
        $hours_0 = (float) str_replace(',', '.', (string) ($request->request->get('hours_0') ?? '0'));
        $hours_1 = (float) str_replace(',', '.', (string) ($request->request->get('hours_1') ?? '0'));
        $hours_2 = (float) str_replace(',', '.', (string) ($request->request->get('hours_2') ?? '0'));
        $hours_3 = (float) str_replace(',', '.', (string) ($request->request->get('hours_3') ?? '0'));
        $hours_4 = (float) str_replace(',', '.', (string) ($request->request->get('hours_4') ?? '0'));
        $hours_5 = (float) str_replace(',', '.', (string) ($request->request->get('hours_5') ?? '0'));
        $hours_6 = (float) str_replace(',', '.', (string) ($request->request->get('hours_6') ?? '0'));
        /** @var User|object|null $user */
        $user = $request->request->get('user_id') ?
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->request->get('user_id'))
            : null;

        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        if (0 !== $contractId) {
            $contract = $objectRepository->find($contractId);
            if (!$contract) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
            if (!$contract instanceof Contract) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $contract = new Contract();
        }

        if (!$user instanceof User) {
            $response = new Response($this->translate('Please enter a valid user.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $dateStart = \DateTime::createFromFormat('Y-m-d', $start ?: '');
        if (!$dateStart) {
            $response = new Response($this->translate('Please enter a valid contract start.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $dateStart->setDate((int) $dateStart->format('Y'), (int) $dateStart->format('m'), (int) $dateStart->format('d'));
        $dateStart->setTime(0, 0, 0);

        $dateEnd = \DateTime::createFromFormat('Y-m-d', $end ?: '');
        if ($dateEnd) {
            $dateEnd->setDate((int) $dateEnd->format('Y'), (int) $dateEnd->format('m'), (int) $dateEnd->format('d'));
            $dateEnd->setTime(23, 59, 59);

            if ($dateEnd < $dateStart) {
                $response = new Response($this->translate('End date has to be greater than the start date.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

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
        if (0 !== $contractId) {
            $objectManager->flush();

            return new JsonResponse([$contract->getId()]);
        }

        // update old contracts,
        $responseMessage = $this->updateOldContract($user, $dateStart, $dateEnd);
        if ('' !== $responseMessage && '0' !== $responseMessage) {
            $response = new Response($responseMessage);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager->flush();

        return new JsonResponse([$contract->getId()]);
    }

    /**
     * Look for existing contracts for user and update the latest if open-ended
     * When updating to PHP8 change return type to string|null.
     */
    protected function updateOldContract(User $user, \DateTime $newStartDate, ?\DateTime $newEndDate): string
    {
        $objectManager = $this->doctrineRegistry->getManager();
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        // get existing contracts for the user
        /** @var array<int, Contract> $contractsOld */
        $contractsOld = $objectRepository->findBy(['user' => $user]);

        if (!$contractsOld) {
            return '';
        }

        if ($this->checkOldContractsStartDateOverlap($contractsOld, $newStartDate, $newEndDate)) {
            return $this->translate('There is already an ongoing contract with a start date in the future that overlaps with the new contract.');
        }

        if ($this->checkOldContractsEndDateOverlap($contractsOld, $newStartDate)) {
            return $this->translate('There is already an ongoing contract with a closed end date in the future.');
        }

        // filter to get only open-ended contracts
        $contractsOld = array_filter($contractsOld, fn ($n): bool => ($n instanceof Contract) && (null == $n->getEnd()));
        if (count($contractsOld) > 1) {
            return $this->translate('There is more than one open-ended contract for the user.');
        }

        if ([] === $contractsOld) {
            return '';
        }

        $contractOld = array_values($contractsOld)[0];
        if (!$contractOld instanceof Contract) {
            return '';
        }

        // alter exisiting contract with open end
        // |--old--(update)
        //      |--new----(|)->
        if ($contractOld->getStart() <= $newStartDate) {
            $oldContractEndDate = clone $newStartDate;
            $contractOld->setEnd($oldContractEndDate->sub(new \DateInterval('P1D')));
            $objectManager->persist($contractOld);
            $objectManager->flush();
        }

        // skip old contract edit for
        // |--new--| |--old--(|)-->
        // and
        // |--old--| |--new--(|)-->
        return '';
    }

    /**
     * look for old contracts that start during the duration of the new contract
     *      |--old----->
     *  |--new--(|)-->.
     */
    /**
     * @param array<int, Contract> $contracts
     */
    protected function checkOldContractsStartDateOverlap(array $contracts, \DateTime $newStartDate, ?\DateTime $newEndDate): bool
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
     *      |--new----->.
     */
    /**
     * @param array<int, Contract> $contracts
     */
    protected function checkOldContractsEndDateOverlap(array $contracts, \DateTime $newStartDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            $startsBeforeOrOnNewDate = $contract->getStart() <= $newStartDate;
            $endsAfterOrOnNewDate = $contract->getEnd() >= $newStartDate;
            $hasEndDate = null !== $contract->getEnd();

            if ($startsBeforeOrOnNewDate && $endsAfterOrOnNewDate && $hasEndDate) {
                $filteredContracts[] = $contract;
            }
        }

        return (bool) $filteredContracts;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/contract/delete', name: 'deleteContract_attr', methods: ['POST'])]
    public function deleteContract(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $contract = $doctrine->getRepository(Contract::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($contract) {
                $em->remove($contract);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }
}
