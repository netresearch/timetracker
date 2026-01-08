<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Enum\UserType;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetUsersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $isDev = UserType::DEV === $user->getType();

        $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
        \assert($userRepo instanceof \App\Repository\UserRepository);
        $data = $isDev ? $userRepo->getUserById($userId) : $userRepo->getUsers($userId);

        return new JsonResponse($data);
    }
}
