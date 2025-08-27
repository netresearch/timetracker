<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetUsersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getUsers', name: '_getUsers_attr', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        if ($this->isDEV($request)) {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $data = $userRepo->getUserById($this->getUserId($request));
        } else {
            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $this->managerRegistry->getRepository(\App\Entity\User::class);
            $data = $userRepo->getUsers($this->getUserId($request));
        }

        return new JsonResponse($data);
    }
}


