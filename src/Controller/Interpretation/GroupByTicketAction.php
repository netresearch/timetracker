<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GroupByTicketAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/ticket', name: 'interpretation_ticket_attr', methods: ['GET'])]
    public function __invoke(Request $request): ModelResponse|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            /** @var \App\Repository\EntryRepository $repo */
            $repo = $this->managerRegistry->getRepository(\App\Entity\Entry::class);
            $entries = $repo->findByFilterArray(['user' => $this->getUserId($request)]);
        } catch (\Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $tickets = [];
        foreach ($entries as $entry) {
            $ticket = $entry->getTicket();
            if ($ticket !== '' && $ticket !== '-') {
                if (!isset($tickets[$ticket])) {
                    $tickets[$ticket] = ['id' => $entry->getId(), 'name' => $ticket, 'hours' => 0, 'quota' => 0];
                }
                $tickets[$ticket]['hours'] += $entry->getDuration() / 60;
            }
        }

        $sum = 0; foreach ($tickets as $t) { $sum += $t['hours']; }
        foreach ($tickets as &$t) { $t['quota'] = $this->timeCalculationService->formatQuota($t['hours'], $sum); }
        usort($tickets, static fn($a, $b) => strcmp((string) $b['name'], (string) $a['name']));

        return new JsonResponse(array_values($tickets));
    }
}


