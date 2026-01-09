<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\CustomerRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetCustomersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllCustomers', name: '_getAllCustomers_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);
        \assert($objectRepository instanceof CustomerRepository);

        return new JsonResponse($objectRepository->getAllCustomers());
    }
}
