<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;

use function assert;

final class GetCustomersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getCustomers', name: '_getCustomers_attr', methods: ['GET'])]
    public function __invoke(#[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\Customer::class);
        assert($objectRepository instanceof \App\Repository\CustomerRepository);
        $data = $objectRepository->getCustomersByUser($userId);

        return new JsonResponse($data);
    }
}
