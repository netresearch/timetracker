<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetTeamsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllTeams', name: '_getAllTeams_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): Response|JsonResponse
    {

        /** @var \App\Repository\TeamRepository<\App\Entity\Team> $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(\App\Entity\Team::class);

        return new JsonResponse($objectRepository->getAllTeamsAsArray());
    }
}
