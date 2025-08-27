<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetProjectStructureAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getProjectStructure', name: '_getProjectStructure_attr', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
        $managerRegistry = $this->managerRegistry;

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Customer::class);
        $customers = $objectRepository->getCustomersByUser($userId);

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $managerRegistry->getRepository(\App\Entity\Project::class);
        $projectStructure = $projectRepo->getProjectStructure($userId, $customers);

        return new JsonResponse($projectStructure);
    }
}


