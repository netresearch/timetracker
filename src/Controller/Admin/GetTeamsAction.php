<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Team;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\TeamRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetTeamsAction extends BaseController
{
    #[Route(path: '/getAllTeams', name: '_getAllTeams_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);
        assert($objectRepository instanceof TeamRepository);

        return new JsonResponse($objectRepository->getAllTeamsAsArray());
    }
}
