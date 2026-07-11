<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Service\Personio\AttendanceExportService;
use App\Service\Sync\SyncRunConsoleRenderer;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function trim;

/**
 * ADR-024 P1: the cron entry point that exports opted-in users' TimeTracker worklogs to Personio as
 * daily WORK attendance periods. Rescans a rolling window (default: last 14 days) and is idempotent —
 * TT owns only the periods it created and reconciles them per run. Without --user every opted-in,
 * employee-mapped user is exported; with --user a single named user is exported.
 */
#[AsCommand(name: 'tt:export-personio-attendances', description: 'Export opted-in users\' worklogs to Personio as daily attendances (ADR-024 P1)')]
class TtExportPersonioAttendancesCommand extends Command
{
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

        if ((null !== $from && '' === trim($from)) || (null !== $to && '' === trim($to))) {
            $symfonyStyle->error('Invalid date in --from/--to: must not be blank (expected Y-m-d)');

            return 1;
        }

        try {
            $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('today')->modify('-14 days');
            $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today');
        } catch (Exception) {
            $symfonyStyle->error(sprintf('Invalid date in --from/--to (expected Y-m-d): %s / %s', $from ?? '-', $to ?? '-'));

            return 1;
        }

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

        $failed = false;
        foreach ($runs as $syncRun) {
            $this->syncRunConsoleRenderer->render($symfonyStyle, $syncRun, 'Personio export');
            if (SyncRunStatus::FAILED === $syncRun->getStatus()) {
                $failed = true;
            }
        }

        return $failed ? 1 : Command::SUCCESS;
    }
}
