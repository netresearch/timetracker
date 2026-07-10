<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\TicketSystem;
use App\Enum\SyncRunStatus;
use App\Service\Sync\SyncRunConsoleRenderer;
use App\Service\Sync\SyncWorklogsService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function trim;

/**
 * ADR-023 (amended): the cron entry point for opt-in per-user Jira worklog sync. Runs both passes
 * for one ticket system — self-sync for authors who opted their own worklogs in, then PO sync-all
 * for everyone a sync-all PO can see — each under its own accountable token. No cursor, no
 * `--since`, no `--user`: a rolling date window is rescanned, idempotent via worklog id.
 */
#[AsCommand(name: 'tt:sync-worklogs', description: 'Opt-in per-user + PO bidirectional Jira worklog sync (ADR-023)')]
class TtSyncWorklogsCommand extends Command
{
    public function __construct(
        private readonly SyncWorklogsService $syncWorklogsService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly SyncRunConsoleRenderer $syncRunConsoleRenderer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        #[Argument(description: 'Ticket system ID', name: 'ticket-system-id')]
        string $ticketSystemId,
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Start date (Y-m-d); default: 30 days ago', name: 'from')]
        ?string $from = null,
        #[Option(description: 'End date (Y-m-d); default: today', name: 'to')]
        ?string $to = null,
        #[Option(description: 'Preview only: counters and parked items, no writes', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $system = $this->managerRegistry->getRepository(TicketSystem::class)->find((int) $ticketSystemId);
        if (!$system instanceof TicketSystem) {
            $symfonyStyle->error('Ticket system not found: ' . $ticketSystemId);

            return 1;
        }

        if ((null !== $from && '' === trim($from)) || (null !== $to && '' === trim($to))) {
            $symfonyStyle->error('Invalid date in --from/--to: must not be blank (expected Y-m-d)');

            return 1;
        }

        try {
            $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('today')->modify('-30 days');
            $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today');
        } catch (Exception) {
            $symfonyStyle->error(sprintf('Invalid date in --from/--to (expected Y-m-d): %s / %s', $from ?? '-', $to ?? '-'));

            return 1;
        }

        $runs = $this->syncWorklogsService->syncTicketSystem($system, $fromDate, $toDate, $dryRun);

        if ([] === $runs) {
            $symfonyStyle->note('Nothing to sync: no user opted in and no PO opted into sync-all for this ticket system.');

            return Command::SUCCESS;
        }

        $failed = false;
        foreach ($runs as $syncRun) {
            $this->syncRunConsoleRenderer->render($symfonyStyle, $syncRun, 'Sync');
            if (SyncRunStatus::FAILED === $syncRun->getStatus()) {
                $failed = true;
            }
        }

        return $failed ? 1 : Command::SUCCESS;
    }
}
