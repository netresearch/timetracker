<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetUsersAction extends BaseController
{
    #[Route(path: '/getAllUsers', name: '_getAllUsers_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[CurrentUser] ?User $user = null): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);
        assert($objectRepository instanceof UserRepository);

        return new JsonResponse($objectRepository->getAllUsers());
    }
}
