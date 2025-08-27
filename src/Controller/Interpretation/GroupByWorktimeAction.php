<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GroupByWorktimeAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/time', name: 'interpretation_time_attr', methods: ['GET'])]
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

        $times = [];
        foreach ($entries as $entry) {
            $day = $entry->getDay();
            if (!$day instanceof \DateTimeInterface) { continue; }
            $key = $day->format('y-m-d');
            if (!isset($times[$key])) {
                $times[$key] = ['id' => null, 'name' => $key, 'day' => $day->format('d.m.'), 'hours' => 0.0, 'minutes' => 0.0, 'quota' => 0];
            }
            $times[$key]['minutes'] += $entry->getDuration();
        }

        $totalMinutes = 0.0; foreach ($times as $t) { $totalMinutes += (float) $t['minutes']; }
        foreach ($times as &$time) {
            $minutes = (float) $time['minutes'];
            $time['hours'] = $minutes / 60.0;
            unset($time['minutes']);
            $time['quota'] = $this->timeCalculationService->formatQuota($minutes, $totalMinutes);
        }

        usort($times, static fn($a, $b) => strcmp((string) $b['name'], (string) $a['name']));
        $prepared = array_map(static fn(array $t): array => [
            'id' => $t['id'], 'name' => $t['name'], 'day' => $t['day'], 'hours' => (float) $t['hours'], 'quota' => (string) $t['quota'],
        ], $times);

        return new JsonResponse(array_values(array_reverse($prepared)));
    }
}


