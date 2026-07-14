<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\ProjectSaveDto;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\BillingType;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ProjectRepository;
use App\Response\Error;
use App\Service\SubticketSyncService;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;

final class SaveProjectAction extends BaseController
{
    private ObjectMapperInterface $objectMapper;

    /**
     * @throws BadRequestException
     * @throws Exception
     */
    #[Route(path: '/project/save', name: 'saveProject_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[MapRequestPayload] ProjectSaveDto $projectSaveDto): Response|Error|JsonResponse
    {
        $ticketSystem = null !== $projectSaveDto->ticket_system ? $this->doctrineRegistry->getRepository(TicketSystem::class)->find($projectSaveDto->ticket_system) : null;

        $projectLead = $this->findUser($projectSaveDto->project_lead);
        $technicalLead = $this->findUser($projectSaveDto->technical_lead);

        $jiraId = $this->normalizeJiraKey($projectSaveDto->jiraId);
        $jiraTicket = $this->normalizeJiraKey($projectSaveDto->jiraTicket);
        $active = $projectSaveDto->active;
        $global = $projectSaveDto->global;
        $estimation = $this->timeCalculationService->readableToFullMinutes($projectSaveDto->estimation);
        $billingType = BillingType::from($projectSaveDto->billing); // Convert int to enum
        $costCenter = $projectSaveDto->cost_center;
        $offer = $projectSaveDto->offer;
        $additionalInformationFromExternal = $projectSaveDto->additionalInformationFromExternal;

        $objectRepository = $this->doctrineRegistry->getRepository(Project::class);
        assert($objectRepository instanceof ProjectRepository);

        $internalJiraTicketSystem = $projectSaveDto->internalJiraTicketSystem;
        $internalJiraProjectKey = $projectSaveDto->internalJiraProjectKey;

        $project = $this->loadOrCreateProject($projectSaveDto, $objectRepository);
        if (!$project instanceof Project) {
            return $project;
        }

        $projectCustomer = $project->getCustomer();
        if (!$projectCustomer instanceof Customer) {
            $response = new Response($this->translate('Please choose a customer.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        // Validation is now handled by the DTO with MapRequestPayload

        if ('' !== $jiraId && 0 === $objectRepository->isValidJiraPrefix($jiraId)) {
            $response = new Response($this->translate('Please provide a valid ticket prefix with only capital letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        // Map scalar fields from DTO to entity - but skip the billing field since we need enum conversion
        $this->objectMapper->map($projectSaveDto, $project);
        // Then set computed/relations and the converted billing enum
        $project
            ->setJiraId($jiraId)
            ->setJiraTicket($jiraTicket)
            ->setActive($active)
            ->setGlobal($global)
            ->setEstimation($estimation)
            ->setProjectLead($projectLead)
            ->setTechnicalLead($technicalLead)
            ->setBilling($billingType) // Use the converted enum
            ->setOffer($offer)
            ->setCostCenter($costCenter)
            ->setAdditionalInformationFromExternal($additionalInformationFromExternal)
            ->setInternalJiraProjectKey($internalJiraProjectKey)
            ->setInternalJiraTicketSystem($internalJiraTicketSystem);

        if ($ticketSystem instanceof TicketSystem) {
            $project->setTicketSystem($ticketSystem);
        } elseif ($project->getTicketSystem() instanceof TicketSystem) {
            $project->setTicketSystem($project->getTicketSystem());
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($project);
        $objectManager->flush();

        $data = [$project->getId(), $projectSaveDto->name, $projectCustomer->getId(), $jiraId];

        if ($ticketSystem instanceof TicketSystem) {
            $syncError = $this->trySyncSubtickets($project);
            if (null !== $syncError) {
                $data['message'] = $syncError;
            }
        }

        return new JsonResponse($data);
    }

    private function findUser(?int $userId): ?User
    {
        return null !== $userId ? $this->doctrineRegistry->getRepository(User::class)->find($userId) : null;
    }

    private function normalizeJiraKey(?string $value): string
    {
        return null !== $value && '' !== $value ? strtoupper($value) : '';
    }

    private function loadOrCreateProject(ProjectSaveDto $projectSaveDto, ProjectRepository $projectRepository): Project|Response|Error
    {
        if (0 !== $projectSaveDto->id) {
            $project = $projectRepository->find($projectSaveDto->id);

            return $project instanceof Project
                ? $project
                : new Error($this->translator->trans('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        /** @var Customer $customer */
        $customer = null !== $projectSaveDto->customer ? $this->doctrineRegistry->getRepository(Customer::class)->find($projectSaveDto->customer) : null;
        if (!$customer instanceof Customer) {
            $response = new Response($this->translate('Please choose a customer.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $project = new Project();
        $project->setCustomer($customer);

        return $project;
    }

    /**
     * Returns the error message when the subticket sync fails, null on success.
     */
    private function trySyncSubtickets(Project $project): ?string
    {
        try {
            if (null !== $project->getId()) {
                $this->subticketSyncService->syncProjectSubtickets($project);
            }

            return null;
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    private TimeCalculationService $timeCalculationService;

    private SubticketSyncService $subticketSyncService;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService, ObjectMapperInterface $objectMapper): void
    {
        $this->timeCalculationService = $timeCalculationService;
        $this->objectMapper = $objectMapper;
    }

    #[Required]
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }
}
