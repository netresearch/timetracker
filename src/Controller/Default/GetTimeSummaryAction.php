<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Enum\Period;
use App\Model\JsonResponse;
use App\Repository\EntryRepository;
use Exception;

final class GetTimeSummaryAction extends BaseController
{
    /**
     * @throws Exception When database operations fail
     * @throws Exception When user ID retrieval or time calculation fails
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTimeSummary', name: 'time_summary_attr', methods: ['GET'])]
    public function __invoke(#[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        \assert($objectRepository instanceof EntryRepository);
        $today = $objectRepository->getWorkByUser($userId, Period::DAY);
        $week = $objectRepository->getWorkByUser($userId, Period::WEEK);
        $month = $objectRepository->getWorkByUser($userId, Period::MONTH);

        $data = [
            'today' => $today,
            'week' => $week,
            'month' => $month,
        ];

        return new JsonResponse($data);
    }
}
