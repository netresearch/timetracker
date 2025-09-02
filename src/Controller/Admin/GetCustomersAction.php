<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;

final class GetCustomersAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllCustomers', name: '_getAllCustomers_attr', methods: ['GET'])]
    public function __invoke(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(\App\Entity\Customer::class);

        return new JsonResponse($objectRepository->getAllCustomers());
    }
}
