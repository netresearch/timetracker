<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GroupByTicketAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/ticket', name: 'interpretation_ticket_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $currentUser,
    ): ModelResponse|JsonResponse {

        try {
            $entries = $this->getEntries($request, $currentUser);
        } catch (Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $tickets = [];
        foreach ($entries as $entry) {
            $ticket = $entry->getTicket();
            if ('' !== $ticket && '-' !== $ticket) {
                if (!isset($tickets[$ticket])) {
                    $tickets[$ticket] = ['id' => $entry->getId(), 'name' => $ticket, 'hours' => 0, 'quota' => 0];
                }

                $tickets[$ticket]['hours'] += $entry->getDuration() / 60;
            }
        }

        $sum = 0;
        foreach ($tickets as $t) {
            $sum += $t['hours'];
        }

        foreach ($tickets as &$ticket) {
            $ticket['quota'] = $this->timeCalculationService->formatQuota($ticket['hours'], $sum);
        }

        usort($tickets, $this->sortByName(...));

        return new JsonResponse($tickets);
    }
}
