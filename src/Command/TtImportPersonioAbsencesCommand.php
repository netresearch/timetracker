<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\Personio\AbsenceImportService;
use App\Service\Sync\SyncRunConsoleRenderer;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
    use SyncWindowCommandTrait;

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

        $window = $this->resolveWindow($symfonyStyle, $from, $to, '-30 days', '+90 days');
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

            $runs = [$this->absenceImportService->importUser($account, $fromDate, $toDate)];
        } else {
            $runs = $this->absenceImportService->importAllOptedIn($fromDate, $toDate);
        }

        if ([] === $runs) {
            $symfonyStyle->note('Nothing to import: no user opted in with a Personio employee id mapped.');

            return Command::SUCCESS;
        }

        return $this->renderRuns($symfonyStyle, $this->syncRunConsoleRenderer, $runs, 'Personio import');
    }
}
