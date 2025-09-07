<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetAllProjectsAction extends BaseController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When query parameters are invalid
     * @throws Exception When database operations fail
     * @throws \InvalidArgumentException When customer ID parameter is invalid
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllProjects', name: '_getAllProjects_attr', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $customerId = (int) $request->query->get('customer');
        $managerRegistry = $this->managerRegistry;
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Project::class);
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
