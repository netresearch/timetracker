<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Contract;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetContractsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getContracts', name: '_getContracts_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): Response|JsonResponse
    {
        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        return new JsonResponse($objectRepository->getContracts());
    }
}
