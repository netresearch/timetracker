<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetActivitiesAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getActivities', name: '_getActivities_attr', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        /** @var \App\Repository\ActivityRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\Activity::class);
        $data = $objectRepository->getActivities();

        return new JsonResponse($data);
    }
}


