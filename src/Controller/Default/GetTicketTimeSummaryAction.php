<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GetTicketTimeSummaryAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTicketTimeSummary/{ticket}', name: '_getTicketTimeSummary_attr', defaults: ['ticket' => null], methods: ['GET'])]
    public function __invoke(Request $request): Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $attributes = $request->attributes;
        $name = $attributes->has('ticket') ? $attributes->get('ticket') : null;

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $activities = $objectRepository->getActivitiesWithTime($name ?? '');
        $users = $objectRepository->getUsersWithTime($name ?? '');

        if (0 === count($users)) {
            return new Response('There is no information available about this ticket.', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        $time = ['total_time' => ['time' => 0]];
        foreach ($activities as $activity) {
            $total = $activity['total_time'];
            $key = $activity['name'] ?? 'No activity';
            $time['activities'][$key]['seconds'] = (int) $total * 60;
            $time['activities'][$key]['time'] = $this->timeCalculationService->minutesToReadable((int) $total);
        }

        foreach ($users as $user) {
            $time['total_time']['time'] += (int) $user['total_time'];
            $key = $user['username'];
            $time['users'][$key]['seconds'] = (int) $user['total_time'] * 60;
            $time['users'][$key]['time'] = $this->timeCalculationService->minutesToReadable((int) $user['total_time']);
        }

        $time['total_time']['seconds'] = $time['total_time']['time'] * 60;
        $time['total_time']['time'] = $this->timeCalculationService->minutesToReadable($time['total_time']['time']);

        return new JsonResponse($time);
    }
}


