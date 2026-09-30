<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\Personio\AttendanceExportService;
use App\Service\Sync\SyncRunConsoleRenderer;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ADR-024 P1: the cron entry point that exports opted-in users' TimeTracker worklogs to Personio as
 * daily WORK attendance periods. Rescans a rolling window (default: last 14 days) and is idempotent —
 * TT owns only the periods it created and reconciles them per run. Without --user every opted-in,
 * employee-mapped user is exported; with --user a single named user is exported.
 */
#[AsCommand(name: 'tt:export-personio-attendances', description: 'Export opted-in users\' worklogs to Personio as daily attendances (ADR-024 P1)')]
class TtExportPersonioAttendancesCommand extends Command
{
    use SyncWindowCommandTrait;

    public function __construct(
        private readonly AttendanceExportService $attendanceExportService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly SyncRunConsoleRenderer $syncRunConsoleRenderer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Start date (Y-m-d); default: 14 days ago', name: 'from')]
        ?string $from = null,
        #[Option(description: 'End date (Y-m-d); default: today', name: 'to')]
        ?string $to = null,
        #[Option(description: 'Export only this TT username; default: every opted-in, employee-mapped user', name: 'user')]
        ?string $user = null,
        #[Option(description: 'Preview only: counters and parked items, no writes', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $window = $this->resolveWindow($symfonyStyle, $from, $to, '-14 days', '+0 days');
        if (null === $window) {
            return Command::FAILURE;
        }

        [$fromDate, $toDate] = $window;

        if (null !== $user) {
            $account = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $user]);
            if (!$account instanceof User) {
                $symfonyStyle->error('User not found: ' . $user);

                return 1;
            }

            $runs = [$this->attendanceExportService->exportUser($account, $fromDate, $toDate, $dryRun)];
        } else {
            $runs = $this->attendanceExportService->exportAllOptedIn($fromDate, $toDate, $dryRun);
        }

        if ([] === $runs) {
            $symfonyStyle->note('Nothing to export: no user opted in with a Personio employee id mapped.');

            return Command::SUCCESS;
        }

        return $this->renderRuns($symfonyStyle, $this->syncRunConsoleRenderer, $runs, 'Personio export');
    }
}
