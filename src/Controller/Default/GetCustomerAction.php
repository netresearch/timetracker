<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetCustomerAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getCustomer', name: '_getCustomer_attr', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): JsonResponse
    {
        $projectParam = $request->query->get('project');
        if (is_scalar($projectParam) && '' !== $projectParam) {
            $project = $this->managerRegistry->getRepository(Project::class)->find($projectParam);
            if ($project instanceof Project && $project->getCustomer() instanceof \App\Entity\Customer) {
                return new JsonResponse(['customer' => $project->getCustomer()->getId()]);
            }

            return new JsonResponse(['customer' => null]);
        }

        return new JsonResponse(['customer' => 0]);
    }
}


