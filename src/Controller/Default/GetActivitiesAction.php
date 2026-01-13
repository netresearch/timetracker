<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Activity;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ActivityRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetActivitiesAction extends BaseController
{
    #[Route(path: '/getActivities', name: '_getActivities_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $objectRepository = $this->managerRegistry->getRepository(Activity::class);
        assert($objectRepository instanceof ActivityRepository);
        $data = $objectRepository->getActivities();

        return new JsonResponse($data);
    }
}
