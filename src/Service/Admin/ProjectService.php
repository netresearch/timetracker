<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Helper\TimeHelper;
use App\Services\SubticketSyncService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for managing projects.
 */
class ProjectService
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SubticketSyncService
     */
    private $subticketSyncService;

    /**
     * ProjectService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine,
        TranslatorInterface $translator,
        RouterInterface $router,
        LoggerInterface $logger,
        SubticketSyncService $subticketSyncService
    ) {
        $this->doctrine = $doctrine;
        $this->translator = $translator;
        $this->router = $router;
        $this->logger = $logger;
        $this->subticketSyncService = $subticketSyncService;
    }

    /**
     * Get all projects.
     *
     * @return array All projects
     */
    public function getAllProjects(): array
    {
        /** @var \App\Repository\ProjectRepository $repository */
        $repository = $this->doctrine->getRepository(Project::class);
        return $repository->findAll();
    }

    /**
     * Save (create or update) a project.
     *
     * @param array $data Project data
     * @param bool $syncSubtickets Whether to sync subtickets after saving
     * @return array Result data with project ID and potential error message
     * @throws \Exception If validation fails
     */
    public function saveProject(array $data, bool $syncSubtickets = true): array
    {
        $projectId = (int) ($data['id'] ?? 0);
        $name = $data['name'] ?? '';
        $ticketSystemId = $data['ticket_system'] ?? null;
        $projectLeadId = $data['project_lead'] ?? null;
        $technicalLeadId = $data['technical_lead'] ?? null;
        $jiraId = isset($data['jiraId']) ? strtoupper((string) $data['jiraId']) : '';
        $jiraTicket = isset($data['jiraTicket']) ? strtoupper((string) $data['jiraTicket']) : '';
        $active = $data['active'] ?? 0;
        $global = $data['global'] ?? 0;
        $estimation = TimeHelper::readable2minutes($data['estimation'] ?? '0m');
        $billing = $data['billing'] ?? 0;
        $costCenter = $data['cost_center'] ?? null;
        $offer = $data['offer'] ?? 0;
        $additionalInformationFromExternal = $data['additionalInformationFromExternal'] ?? 0;
        $internalJiraTicketSystem = (int) ($data['internalJiraTicketSystem'] ?? 0);
        $internalJiraProjectKey = $data['internalJiraProjectKey'] ?? 0;
        $customerId = $data['customer'] ?? null;

        /** @var \App\Repository\ProjectRepository $projectRepository */
        $projectRepository = $this->doctrine->getRepository(Project::class);

        // Get ticket system if provided
        $ticketSystem = null;
        if ($ticketSystemId) {
            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->doctrine->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($ticketSystemId);
        }

        // Get project lead if provided
        $projectLead = null;
        if ($projectLeadId) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->doctrine->getRepository(User::class);
            $projectLead = $userRepo->find($projectLeadId);
        }

        // Get technical lead if provided
        $technicalLead = null;
        if ($technicalLeadId) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->doctrine->getRepository(User::class);
            $technicalLead = $userRepo->find($technicalLeadId);
        }

        if ($projectId !== 0) {
            // Update existing project
            $project = $projectRepository->find($projectId);
            if (!$project) {
                throw new \Exception($this->translator->trans('Project not found.'));
            }
        } else {
            // Create new project
            $project = new Project();

            // Set customer for new project
            if ($customerId) {
                /** @var Customer $customer */
                $customer = $this->doctrine->getRepository(Customer::class)
                    ->find($customerId);

                if (!$customer) {
                    throw new \Exception($this->translator->trans('Please choose a customer.'));
                }

                $project->setCustomer($customer);
            } else {
                throw new \Exception($this->translator->trans('Please choose a customer.'));
            }
        }

        // Validate project name
        if (strlen((string) $name) < 3) {
            throw new \Exception($this->translator->trans('Please provide a valid project name with at least 3 letters.'));
        }

        // Check for duplicate project name within the same customer
        $sameNamedProject = $projectRepository->findOneBy(
            ['name' => $name, 'customer' => $project->getCustomer()->getId()]
        );
        if ($sameNamedProject && $project->getId() != $sameNamedProject->getId()) {
            throw new \Exception($this->translator->trans('The project name provided already exists.'));
        }

        // Validate Jira ID format
        if (strlen($jiraId) && false == $projectRepository->isValidJiraPrefix($jiraId)) {
            throw new \Exception($this->translator->trans('Please provide a valid ticket prefix with only capital letters.'));
        }

        // Update project properties
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

        // Save the project
        $objectManager = $this->doctrine->getManager();
        $objectManager->persist($project);
        $objectManager->flush();

        $result = [
            'id' => $project->getId(),
            'name' => $name,
            'customerId' => $project->getCustomer()->getId(),
            'jiraId' => $jiraId,
            0 => $project->getId(),
            1 => $name,
            2 => $project->getCustomer()->getId()
        ];

        // Sync subtickets if requested and ticket system is set
        if ($syncSubtickets && $ticketSystem) {
            try {
                $subtickets = $this->subticketSyncService->syncProjectSubtickets($project->getId());
                $result['subtickets'] = $subtickets;
            } catch (\Exception $e) {
                $result['message'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Delete a project.
     *
     * @param int $projectId Project ID to delete
     * @return bool True if successful
     * @throws \Exception If deletion fails
     */
    public function deleteProject(int $projectId): bool
    {
        try {
            $project = $this->doctrine->getRepository(Project::class)
                ->find($projectId);

            if (!$project) {
                throw new \Exception($this->translator->trans('Project not found.'));
            }

            $em = $this->doctrine->getManager();
            $em->remove($project);
            $em->flush();

            return true;
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translator->trans('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translator->trans('Dataset could not be removed. %s'), $reason);
            throw new \Exception($msg, 422);
        }
    }

    /**
     * Sync subtickets for a specific project.
     *
     * @param int $projectId Project ID
     * @return array Subtickets data
     * @throws \Exception If sync fails
     */
    public function syncProjectSubtickets(int $projectId): array
    {
        return $this->subticketSyncService->syncProjectSubtickets($projectId);
    }

    /**
     * Sync subtickets for all projects with a ticket system.
     *
     * @return bool True if successful
     * @throws \Exception If sync fails
     */
    public function syncAllProjectSubtickets(): bool
    {
        /** @var \App\Repository\ProjectRepository $projectRepository */
        $projectRepository = $this->doctrine->getRepository(Project::class);
        $projects = $projectRepository->createQueryBuilder('p')
            ->where('p.ticketSystem IS NOT NULL')
            ->getQuery()
            ->getResult();

        foreach ($projects as $project) {
            $this->subticketSyncService->syncProjectSubtickets($project->getId());
        }

        return true;
    }
}
