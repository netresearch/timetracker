<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetDataAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getData', name: '_getData_attr', methods: ['GET', 'POST'])]
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getData/days/{days}', name: '_getDataDays_attr', defaults: ['days' => 3], methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return new JsonResponse(['error' => 'not authenticated'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        $userId = $this->getUserId($request);
        $user = $this->managerRegistry->getRepository(User::class)->find($userId);

        $days = $request->attributes->has('days') ? (int) $request->attributes->get('days') : 3;
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        if (!$user instanceof User) {
            return new JsonResponse([]);
        }

        $data = $objectRepository->getEntriesByUser($userId, $days, $user->getShowFuture());

        return new JsonResponse($data);
    }
}


