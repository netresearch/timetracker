<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;

final class GetActivitiesAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getActivities', name: '_getActivities_attr', methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\Activity::class);
        $data = $objectRepository->getActivities();

        return new JsonResponse($data);
    }
}
