<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetUsersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (null === $user) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $isDev = $user->getType() === 'DEV';

        if ($isDev) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $data = $userRepo->getUserById($userId);
        } else {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $data = $userRepo->getUsers($userId);
        }

        return new JsonResponse($data);
    }
}


