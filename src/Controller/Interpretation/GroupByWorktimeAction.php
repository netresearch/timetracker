<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Entity\Contract;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Repository\ContractRepository;
use App\Service\Util\ContractHoursResolver;
use App\Service\Util\TimeCalculationService;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;
use function is_string;

final class GroupByWorktimeAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    private ContractHoursResolver $contractHoursResolver;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[Required]
    public function setContractHoursResolver(ContractHoursResolver $contractHoursResolver): void
    {
        $this->contractHoursResolver = $contractHoursResolver;
    }

    #[Route(path: '/interpretation/time', name: 'interpretation_time_attr', methods: ['GET'])]
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

        // Load the user's contracts once; the per-day "expected" (Soll) is then
        // resolved in PHP (validContract + weekdayHours) without a query per day.
        $contractRepository = $this->managerRegistry->getRepository(Contract::class);
        assert($contractRepository instanceof ContractRepository);
        $contracts = $contractRepository->findBy(['user' => $currentUser], ['start' => 'DESC']);

        $times = [];
        foreach ($entries as $entry) {
            $day = $entry->getDay();
            if (!$day instanceof DateTimeInterface) {
                continue;
            }

            $key = $day->format('y-m-d');
            if (!isset($times[$key])) {
                $times[$key] = ['id' => null, 'name' => $key, 'day' => $day->format('d.m.'), 'date' => $day, 'hours' => 0.0, 'minutes' => 0.0, 'quota' => 0, 'expected' => 0.0];
            }

            $times[$key]['minutes'] += $entry->getDuration();
        }

        // The per-day "expected" (Soll) is 0 on a public holiday (matching the
        // /ui/month calendar), otherwise the contract's hours for that weekday
        // (5×8h default when no contract applies).
        $holidayDates = $this->loadHolidayDates($times);

        $totalMinutes = 0.0;
        foreach ($times as $t) {
            $totalMinutes += $t['minutes'];
        }

        foreach ($times as &$time) {
            $minutes = $time['minutes'];
            $date = $time['date'];
            $time['hours'] = $minutes / 60.0;
            $time['expected'] = isset($holidayDates[$date->format('Y-m-d')])
                ? 0.0
                : $this->contractHoursResolver->weekdayHours(
                    $this->contractHoursResolver->validContract($contracts, $date),
                    (int) $date->format('w'),
                );
            unset($time['minutes'], $time['date']);
            $time['quota'] = $this->timeCalculationService->formatQuota($minutes, $totalMinutes);
        }
        unset($time);

        usort($times, $this->sortByName(...));
        $prepared = array_map(static fn (array $t): array => [
            'id' => $t['id'], 'name' => $t['name'], 'day' => $t['day'], 'hours' => $t['hours'], 'quota' => $t['quota'], 'expected' => $t['expected'],
        ], $times);

        $prepared = array_reverse($prepared);

        return new JsonResponse($prepared);
    }

    /**
     * Public-holiday dates ('Y-m-d' => true) spanning the booked days, queried
     * once so the per-day Soll can drop to 0 on a holiday (matching /ui/month).
     *
     * @param array<string, array{date: DateTimeInterface, ...}> $times
     *
     * @return array<string, true>
     */
    private function loadHolidayDates(array $times): array
    {
        if ([] === $times) {
            return [];
        }

        $dates = array_map(static fn (array $time): string => $time['date']->format('Y-m-d'), $times);

        /** @var Connection $connection */
        $connection = $this->managerRegistry->getConnection();
        $rows = $connection->fetchFirstColumn(
            'SELECT day FROM holidays WHERE day BETWEEN ? AND ?',
            [min($dates), max($dates)],
        );

        $holidays = [];
        foreach ($rows as $row) {
            // A DATE column comes back as a 'Y-m-d' string; ignore anything else.
            if (is_string($row)) {
                $holidays[substr($row, 0, 10)] = true;
            }
        }

        return $holidays;
    }
}
