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
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;

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
                $expected = $this->contractHoursResolver->weekdayHours(
                    $this->contractHoursResolver->validContract($contracts, $day),
                    (int) $day->format('w'),
                );
                $times[$key] = ['id' => null, 'name' => $key, 'day' => $day->format('d.m.'), 'hours' => 0.0, 'minutes' => 0.0, 'quota' => 0, 'expected' => $expected];
            }

            $times[$key]['minutes'] += $entry->getDuration();
        }

        $totalMinutes = 0.0;
        foreach ($times as $t) {
            $totalMinutes += $t['minutes'];
        }

        foreach ($times as &$time) {
            $minutes = $time['minutes'];
            $time['hours'] = $minutes / 60.0;
            unset($time['minutes']);
            $time['quota'] = $this->timeCalculationService->formatQuota($minutes, $totalMinutes);
        }

        usort($times, $this->sortByName(...));
        $prepared = array_map(static fn (array $t): array => [
            'id' => $t['id'], 'name' => $t['name'], 'day' => $t['day'], 'hours' => $t['hours'], 'quota' => $t['quota'], 'expected' => $t['expected'],
        ], $times);

        $prepared = array_reverse($prepared);

        return new JsonResponse($prepared);
    }
}
