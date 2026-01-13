<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function count;
use function is_string;

final class GetTicketTimeSummaryAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    /**
     * @throws Exception                                                       When database operations fail
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When route parameters are invalid
     * @throws Exception                                                       When time calculation operations fail
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTicketTimeSummary/{ticket}', name: '_getTicketTimeSummary_attr', defaults: ['ticket' => null], methods: ['GET'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (! $user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $attributes = $request->attributes;

        // Priority 1: Fix MixedAssignment with proper type assertion
        $ticketParam = $attributes->has('ticket') ? $attributes->get('ticket') : null;
        $ticket = is_string($ticketParam) ? $ticketParam : '';

        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof \App\Repository\EntryRepository);
        $activities = $objectRepository->getActivitiesWithTime($ticket);
        $users = $objectRepository->getUsersWithTime($ticket);

        if (0 === count($users)) {
            return new Response('There is no information available about this ticket.', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        $time = ['total_time' => ['time' => 0]];
        foreach ($activities as $activity) {
            $total = $activity['total_time'];
            $key = $activity['name'] ?? 'No activity';
            // Priority 3: Remove redundant cast - total_time is already int from repository
            $time['activities'][$key]['seconds'] = $total * 60;
            $time['activities'][$key]['time'] = $this->timeCalculationService->minutesToReadable($total);
        }

        foreach ($users as $userData) {
            $time['total_time']['time'] += $userData['total_time'];
            $key = $userData['username'];
            // Priority 3: Remove redundant cast - total_time is already int from repository
            $time['users'][$key]['seconds'] = $userData['total_time'] * 60;
            $time['users'][$key]['time'] = $this->timeCalculationService->minutesToReadable($userData['total_time']);
        }

        $time['total_time']['seconds'] = $time['total_time']['time'] * 60;
        $time['total_time']['time'] = $this->timeCalculationService->minutesToReadable($time['total_time']['time']);

        return new JsonResponse($time);
    }
}
