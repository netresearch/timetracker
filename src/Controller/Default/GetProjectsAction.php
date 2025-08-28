<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetProjectsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getProjects', name: '_getProjects_attr', methods: ['GET'])]
    #[\Symfony\Bundle\SecurityBundle\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] \App\Entity\User $user): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        $customerId = (int) $request->query->get('customer');
        $userId = (int) $user->getId();
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        $data = $objectRepository->getProjectsByUser($userId, $customerId);

        return new JsonResponse($data);
    }
}


