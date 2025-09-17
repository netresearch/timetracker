<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetTicketSystemsAction extends BaseController
{
    /**
     * @throws Exception
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTicketSystems', name: '_getTicketSystems_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): Response|JsonResponse
    {

        /** @var \App\Repository\TicketSystemRepository<TicketSystem> $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystems = $objectRepository->getAllTicketSystems();

        // Since this controller requires ROLE_ADMIN, all users accessing it are admins
        // No need to filter sensitive data

        return new JsonResponse($ticketSystems);
    }
}
