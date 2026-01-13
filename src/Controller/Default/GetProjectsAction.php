<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ProjectRepository;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetProjectsAction extends BaseController
{
    /**
     * @throws Exception             When database operations fail
     * @throws BadRequestException   When request is malformed
     * @throws AccessDeniedException When user lacks required permissions
     */
    #[Route(path: '/getProjects', name: '_getProjects_attr', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        // Check if user is either admin or PL type
        if (!$this->isGranted('ROLE_ADMIN') && 'PL' !== $user->getType()->value) {
            $response = new Response($this->translate('You are not allowed to perform this action.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        assert($objectRepository instanceof ProjectRepository);
        $data = $objectRepository->getAllProjectsForAdmin();

        return new JsonResponse($data);
    }
}
