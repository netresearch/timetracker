<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function assert;

final class GetProjectStructureAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getProjectStructure', name: '_getProjectStructure_attr', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (! $user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();

        $objectRepository = $this->managerRegistry->getRepository(Customer::class);
        assert($objectRepository instanceof \App\Repository\CustomerRepository);
        $customers = $objectRepository->getCustomersByUser($userId);

        $projectRepo = $this->managerRegistry->getRepository(\App\Entity\Project::class);
        assert($projectRepo instanceof \App\Repository\ProjectRepository);
        $projectStructure = $projectRepo->getProjectStructure($userId, $customers);

        return new JsonResponse($projectStructure);
    }
}
