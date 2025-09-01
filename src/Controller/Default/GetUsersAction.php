<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetUsersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$user instanceof \App\Entity\User) {
            if (!$this->checkLogin($request)) {
                return $this->redirectToRoute('_login');
            }

            $userId = $this->getUserId($request);
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $current = $userRepo->find($userId);
            $isDev = $current && method_exists($current, 'getType') && $current->getType() === 'DEV';
        } else {
            $userId = (int) $user->getId();
            $isDev = $user->getType() === 'DEV';
        }

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
        $data = $isDev ? $userRepo->getUserById($userId) : $userRepo->getUsers($userId);

        return new JsonResponse($data);
    }
}


