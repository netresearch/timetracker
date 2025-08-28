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
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (null === $user) {
            return $this->redirectToRoute('_login');
        }

        $customerId = (int) $request->query->get('customer');
        $userId = (int) $user->getId();
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        $data = $objectRepository->getProjectsByUser($userId, $customerId);

        return new JsonResponse($data);
    }
}


