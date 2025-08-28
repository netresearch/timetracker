<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GroupByWorktimeAction extends BaseInterpretationController
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
            $entries = $this->getEntries($request);
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

        $totalMinutes = 0.0; foreach ($times as $t) { $totalMinutes += $t['minutes']; }
        foreach ($times as &$time) {
            $minutes = $time['minutes'];
            $time['hours'] = $minutes / 60.0;
            unset($time['minutes']);
            $time['quota'] = $this->timeCalculationService->formatQuota($minutes, $totalMinutes);
        }

        usort($times, $this->sortByName(...));
        $prepared = array_map(static fn(array $t): array => [
            'id' => $t['id'], 'name' => $t['name'], 'day' => $t['day'], 'hours' => (float) $t['hours'], 'quota' => (string) $t['quota'],
        ], $times);

        $prepared = array_reverse($prepared);
        return new JsonResponse($prepared);
    }
}


