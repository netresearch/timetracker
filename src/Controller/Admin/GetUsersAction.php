<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;

final class GetUsersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllUsers', name: '_getAllUsers_attr', methods: ['GET'])]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): Response|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (null === $user) {
            return $this->redirectToRoute('_login');
        }

        /** @var \App\Repository\UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(\App\Entity\User::class);

        return new JsonResponse($objectRepository->getAllUsers());
    }
}



