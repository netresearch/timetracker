<?php

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
    public function __invoke(#[MapRequestPayload] ProjectSaveDto $projectSaveDto): Response|Error|JsonResponse
    {
        $projectId = $projectSaveDto->id;

        $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystem = null !== $projectSaveDto->ticket_system ? $this->doctrineRegistry->getRepository(TicketSystem::class)->find($projectSaveDto->ticket_system) : null;

        $this->doctrineRegistry->getRepository(User::class);
        $projectLead = null !== $projectSaveDto->project_lead ? $this->doctrineRegistry->getRepository(User::class)->find($projectSaveDto->project_lead) : null;
        $technicalLead = null !== $projectSaveDto->technical_lead ? $this->doctrineRegistry->getRepository(User::class)->find($projectSaveDto->technical_lead) : null;

        $jiraId = null !== $projectSaveDto->jiraId && '' !== $projectSaveDto->jiraId ? strtoupper($projectSaveDto->jiraId) : '';
        $jiraTicket = null !== $projectSaveDto->jiraTicket && '' !== $projectSaveDto->jiraTicket ? strtoupper($projectSaveDto->jiraTicket) : '';
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

        if (0 !== $projectId) {
            $project = $objectRepository->find($projectId);
            if (!$project instanceof Project) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $project = new Project();

            /** @var Customer $customer */
            $customer = null !== $projectSaveDto->customer ? $this->doctrineRegistry->getRepository(Customer::class)->find($projectSaveDto->customer) : null;
            if (!$customer instanceof Customer) {
                $response = new Response($this->translate('Please choose a customer.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }

            $project->setCustomer($customer);
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
            try {
                if (null !== $project->getId()) {
                    $this->subticketSyncService->syncProjectSubtickets($project);
                }
            } catch (Exception $e) {
                $data['message'] = $e->getMessage();
            }
        }

        return new JsonResponse($data);
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
