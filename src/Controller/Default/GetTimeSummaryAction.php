<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Contract;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\Period;
use App\Model\JsonResponse;
use App\Repository\ContractRepository;
use App\Repository\EntryRepository;
use App\Security\ApiToken\RequireScope;
use App\Service\ClockInterface;
use App\Service\Util\ExpectedWorkTimeCalculator;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;
use function is_string;
use function min;
use function substr;

final class GetTimeSummaryAction extends BaseController
{
    private ClockInterface $clock;

    private ExpectedWorkTimeCalculator $expectedWorkTimeCalculator;

    #[Required]
    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    #[Required]
    public function setExpectedWorkTimeCalculator(ExpectedWorkTimeCalculator $expectedWorkTimeCalculator): void
    {
        $this->expectedWorkTimeCalculator = $expectedWorkTimeCalculator;
    }

    /**
     * Today/week/month worked minutes (IST) plus the expected minutes (SOLL)
     * for the same periods, so the header can show a running +/- balance and
     * colour each total by whether it meets its target.
     *
     * SOLL is summed from period start *through today* (not the whole week or
     * month), matching the /ui/month "expected until today" balance, so mid-week
     * or mid-month the figures compare like with like.
     *
     * @throws Exception When database operations fail
     * @throws Exception When user ID retrieval or time calculation fails
     */
    #[RequireScope('reporting:read')]
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

        $target = $this->targetMinutes($user);

        $data = [
            'today' => $today + ['target' => $target['today']],
            'week' => $week + ['target' => $target['week']],
            'month' => $month + ['target' => $target['month']],
        ];

        return new JsonResponse($data);
    }

    /**
     * Expected ("Soll") minutes from each period's start through today, keyed
     * today/week/month. Weekend/holiday days contribute 0 (per the user's
     * contract), so the balance matches the rest of the app.
     *
     * @return array{today: int, week: int, month: int}
     */
    private function targetMinutes(User $user): array
    {
        $today = $this->clock->today();
        $startOfWeek = $this->mondayThisWeek($today);
        // modify() is well-defined here; the guard only satisfies the analyser
        // (DateTimeImmutable::modify is typed to allow a false return).
        $firstOfMonth = $today->modify('first day of this month');
        $startOfMonth = false !== $firstOfMonth ? $firstOfMonth : $today;

        $contractRepository = $this->managerRegistry->getRepository(Contract::class);
        assert($contractRepository instanceof ContractRepository);
        /** @var Contract[] $contracts */
        $contracts = $contractRepository->findBy(['user' => $user], ['start' => 'DESC']);

        // One holiday query covering the earliest start we need (the Monday of
        // this week can fall in the previous month) through today.
        $rangeStart = min($startOfWeek, $startOfMonth);
        $holidays = $this->loadHolidayDates($rangeStart, $today);

        return [
            'today' => $this->expectedWorkTimeCalculator->minutesForRange($contracts, $holidays, $today, $today),
            'week' => $this->expectedWorkTimeCalculator->minutesForRange($contracts, $holidays, $startOfWeek, $today),
            'month' => $this->expectedWorkTimeCalculator->minutesForRange($contracts, $holidays, $startOfMonth, $today),
        ];
    }

    private function mondayThisWeek(DateTimeImmutable $today): DateTimeImmutable
    {
        $monday = $today->modify('monday this week');

        // 'monday this week' is well-defined; guard only to satisfy the analyser.
        return false !== $monday ? $monday : $today;
    }

    /**
     * Public-holiday dates ('Y-m-d' => true) in the inclusive range, so SOLL can
     * drop to 0 on a holiday (matching /ui/month and /interpretation/time).
     *
     * @return array<string, true>
     */
    private function loadHolidayDates(DateTimeInterface $from, DateTimeInterface $to): array
    {
        /** @var Connection $connection */
        $connection = $this->managerRegistry->getConnection();
        $rows = $connection->fetchFirstColumn(
            'SELECT day FROM holidays WHERE day BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
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
