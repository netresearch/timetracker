<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetCustomersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getCustomers', name: '_getCustomers_attr', methods: ['GET'])]
    public function __invoke(#[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\Customer::class);
        $data = $objectRepository->getCustomersByUser($userId);

        return new JsonResponse($data);
    }
}


