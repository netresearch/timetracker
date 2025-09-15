<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Model\JsonResponse;
use App\Model\Response;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetProjectsAction extends BaseController
{
    /**
     * @throws Exception                                                        When database operations fail
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException  When request is malformed
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException When user lacks required permissions
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getProjects', name: '_getProjects_attr', methods: ['GET'])]
    #[IsGranted("ROLE_USER")]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        // Check if user is either admin or PL type
        if (!$this->isGranted('ROLE_ADMIN') && $user->getType()->value !== 'PL') {
            $response = new Response($this->translate('Permission denied.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
            return $response;
        }

        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        $data = $objectRepository->getAllProjectsForAdmin();

        return new JsonResponse($data);
    }
}
