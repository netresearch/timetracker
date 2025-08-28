<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetUsersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    #[\Symfony\Bundle\SecurityBundle\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] \App\Entity\User $user): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if ($this->isDEV($request)) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $data = $userRepo->getUserById((int) $user->getId());
        } else {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $data = $userRepo->getUsers((int) $user->getId());
        }

        return new JsonResponse($data);
    }
}


