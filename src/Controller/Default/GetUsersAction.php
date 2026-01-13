<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\User;
use App\Enum\UserType;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetUsersAction extends BaseController
{
    #[Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $isDev = UserType::DEV === $user->getType();

        $userRepo = $this->managerRegistry->getRepository(User::class);
        assert($userRepo instanceof UserRepository);
        $data = $isDev ? $userRepo->getUserById($userId) : $userRepo->getUsers($userId);

        return new JsonResponse($data);
    }
}
