<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Entity\Project;
use App\Model\JsonResponse;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_scalar;

final class GetCustomerAction extends BaseController
{
    /**
     * @throws Exception                When database operations fail
     * @throws BadRequestException      When query parameters are invalid
     * @throws InvalidArgumentException When project parameter is invalid
     */
    #[Route(path: '/getCustomer', name: '_getCustomer_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): JsonResponse
    {
        $projectParam = $request->query->get('project');
        if (is_scalar($projectParam) && '' !== $projectParam) {
            $project = $this->managerRegistry->getRepository(Project::class)->find($projectParam);
            if ($project instanceof Project && $project->getCustomer() instanceof Customer) {
                return new JsonResponse(['customer' => $project->getCustomer()->getId()]);
            }

            return new JsonResponse(['customer' => null]);
        }

        return new JsonResponse(['customer' => 0]);
    }
}
