<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetProjectStructureAction extends BaseController
{
    #[Route(path: '/getProjectStructure', name: '_getProjectStructure_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();

        $objectRepository = $this->managerRegistry->getRepository(Customer::class);
        assert($objectRepository instanceof CustomerRepository);
        $customers = $objectRepository->getCustomersByUser($userId);

        $projectRepo = $this->managerRegistry->getRepository(Project::class);
        assert($projectRepo instanceof ProjectRepository);
        $projectStructure = $projectRepo->getProjectStructure($userId, $customers);

        return new JsonResponse($projectStructure);
    }
}
