<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Contract;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ContractRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetContractsAction extends BaseController
{
    #[Route(path: '/getContracts', name: '_getContracts_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);
        assert($objectRepository instanceof ContractRepository);

        return new JsonResponse($objectRepository->getContracts());
    }
}
