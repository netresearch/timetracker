<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Model\JsonResponse;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function assert;

final class GetAllProjectsAction extends BaseController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When query parameters are invalid
     * @throws Exception                                                       When database operations fail
     * @throws InvalidArgumentException                                        When customer ID parameter is invalid
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllProjects', name: '_getAllProjects_attr', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (! $user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $customerId = (int) $request->query->get('customer');
        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        assert($objectRepository instanceof \App\Repository\ProjectRepository);
        /** @var array<int, Project> $result */
        $result = $customerId > 0 ? $objectRepository->findByCustomer($customerId) : $objectRepository->findAll();

        $data = [];
        foreach ($result as $project) {
            if ($project instanceof Project) {
                $data[] = ['project' => $project->toArray()];
            }
        }

        return new JsonResponse($data);
    }
}
