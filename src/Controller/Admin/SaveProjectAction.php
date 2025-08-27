<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Util\RequestEntityHelper;
use App\Util\RequestHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;
use App\Service\Util\TimeCalculationService;
use App\Service\SubticketSyncService;

final class SaveProjectAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/project/save', name: 'saveProject_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $projectId = (int) $request->request->get('id');
        $name = RequestHelper::string($request, 'name');

        $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystem = RequestEntityHelper::ticketSystem($request, $this->doctrineRegistry, 'ticket_system');

        $this->doctrineRegistry->getRepository(User::class);
        $projectLead = RequestEntityHelper::user($request, $this->doctrineRegistry, 'project_lead');
        $technicalLead = RequestEntityHelper::user($request, $this->doctrineRegistry, 'technical_lead');

        $jiraId = RequestHelper::upperString($request, 'jiraId');
        $jiraTicket = RequestHelper::upperString($request, 'jiraTicket');
        $active = RequestHelper::bool($request, 'active');
        $global = RequestHelper::bool($request, 'global');
        $estimation = $this->timeCalculationService->readableToFullMinutes(RequestHelper::string($request, 'estimation', '0m'));
        $billing = RequestHelper::int($request, 'billing', 0);
        $costCenter = RequestHelper::nullableString($request, 'cost_center');
        $offer = RequestHelper::nullableString($request, 'offer');
        $additionalInformationFromExternal = RequestHelper::bool($request, 'additionalInformationFromExternal');
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Project::class);
        $internalJiraTicketSystem = $request->request->get('internalJiraTicketSystem');
        if ('' === $internalJiraTicketSystem || null === $internalJiraTicketSystem) {
            $internalJiraTicketSystem = null;
        } else {
            $internalJiraTicketSystem = (string) $internalJiraTicketSystem;
        }

        $internalJiraProjectKey = (string) $request->request->get('internalJiraProjectKey', '');

        if (0 !== $projectId) {
            $project = $objectRepository->find($projectId);
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

        if (strlen($name) < 3) {
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

        $sameNamedProject = $objectRepository->findOneBy(
            ['name' => $name, 'customer' => $projectCustomer->getId()]
        );
        if ($sameNamedProject instanceof Project && $project->getId() !== $sameNamedProject->getId()) {
            $response = new Response($this->translate('The project name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (strlen($jiraId) > 1 && $project->getJiraId() !== $jiraId && $ticketSystem instanceof TicketSystem) {
            $search['ticketSystem'] = $ticketSystem;
        }

        if (strlen($jiraId) && false == $objectRepository->isValidJiraPrefix($jiraId)) {
            $response = new Response($this->translate('Please provide a valid ticket prefix with only capital letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $project
            ->setName($name)
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

        if ($ticketSystem instanceof TicketSystem) {
            $project->setTicketSystem($ticketSystem);
        } elseif ($project->getTicketSystem() instanceof TicketSystem) {
            $project->setTicketSystem($project->getTicketSystem());
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($project);
        $objectManager->flush();

        $data = [$project->getId(), $name, $projectCustomer->getId(), $jiraId];

        if ($ticketSystem instanceof TicketSystem) {
            try {
                if (null !== $project->getId()) {
                    $this->subticketSyncService->syncProjectSubtickets($project);
                }
            } catch (\Exception $e) {
                $data['message'] = $e->getMessage();
            }
        }

        return new JsonResponse($data);
    }

    private TimeCalculationService $timeCalculationService;
    private SubticketSyncService $subticketSyncService;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $svc): void
    {
        $this->timeCalculationService = $svc;
    }

    #[Required]
    public function setSubticketSyncService(SubticketSyncService $svc): void
    {
        $this->subticketSyncService = $svc;
    }
}



