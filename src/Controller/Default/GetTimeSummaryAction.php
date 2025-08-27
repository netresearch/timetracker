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
    public function __invoke(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
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


