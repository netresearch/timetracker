<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Service\Personio\AbsenceImportService;
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
 * ADR-024 P2: the cron entry point that imports opted-in users' Personio absences
 * (vacation/sick) as TimeTracker day entries. Rescans a rolling window (default:
 * 30 days back, 90 days ahead — vacations lie in the future) and is idempotent:
 * unchanged absences are skipped, changed ones rebuilt, and cancellations delete
 * their entries. Without --user every opted-in, employee-mapped user is imported;
 * with --user a single named user is imported.
 */
#[AsCommand(name: 'tt:import-personio-absences', description: 'Import opted-in users\' Personio absences as TimeTracker entries (ADR-024 P2)')]
class TtImportPersonioAbsencesCommand extends Command
{
    public function __construct(
        private readonly AbsenceImportService $absenceImportService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly SyncRunConsoleRenderer $syncRunConsoleRenderer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Start date (Y-m-d); default: 30 days ago', name: 'from')]
        ?string $from = null,
        #[Option(description: 'End date (Y-m-d); default: 90 days ahead', name: 'to')]
        ?string $to = null,
        #[Option(description: 'Import only this TT username; default: every opted-in, employee-mapped user', name: 'user')]
        ?string $user = null,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        if ((null !== $from && '' === trim($from)) || (null !== $to && '' === trim($to))) {
            $symfonyStyle->error('Invalid date in --from/--to: must not be blank (expected Y-m-d)');

            return 1;
        }

        try {
            $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('today')->modify('-30 days');
            $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today')->modify('+90 days');
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

            $runs = [$this->absenceImportService->importUser($account, $fromDate, $toDate)];
        } else {
            $runs = $this->absenceImportService->importAllOptedIn($fromDate, $toDate);
        }

        if ([] === $runs) {
            $symfonyStyle->note('Nothing to import: no user opted in with a Personio employee id mapped.');

            return Command::SUCCESS;
        }

        $failed = false;
        foreach ($runs as $syncRun) {
            $this->syncRunConsoleRenderer->render($symfonyStyle, $syncRun, 'Personio import');
            if (SyncRunStatus::FAILED === $syncRun->getStatus()) {
                $failed = true;
            }
        }

        return $failed ? 1 : Command::SUCCESS;
    }
}
