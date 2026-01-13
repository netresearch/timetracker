<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\Period;
use App\Model\JsonResponse;
use App\Repository\EntryRepository;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function assert;

final class GetTimeSummaryAction extends BaseController
{
    /**
     * @throws Exception When database operations fail
     * @throws Exception When user ID retrieval or time calculation fails
     */
    #[Route(path: '/getTimeSummary', name: 'time_summary_attr', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse|RedirectResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
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
