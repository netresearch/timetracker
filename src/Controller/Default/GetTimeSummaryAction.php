<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Model\JsonResponse;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\Request;

final class GetTimeSummaryAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTimeSummary', name: 'time_summary_attr', methods: ['GET'])]
    #[\Symfony\Bundle\SecurityBundle\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] \App\Entity\User $user): JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $userId = (int) $user->getId();
        /** @var EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $today = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_DAY);
        $week = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_WEEK);
        $month = $objectRepository->getWorkByUser($userId, EntryRepository::PERIOD_MONTH);

        $data = [
            'today' => $today,
            'week' => $week,
            'month' => $month,
        ];

        return new JsonResponse($data);
    }
}


