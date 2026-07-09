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

use function ctype_digit;
use function sprintf;

#[AsCommand(name: 'tt:sync-worklogs', description: 'Incremental bidirectional Jira worklog sync (ADR-023)')]
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
        #[Option(description: 'Cursor override: Y-m-d date or epoch milliseconds; default: stored cursor', name: 'since')]
        ?string $since = null,
        #[Option(description: 'Preview only: counters and parked items, no writes, cursor not advanced', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $system = $this->managerRegistry->getRepository(TicketSystem::class)->find((int) $ticketSystemId);
        if (!$system instanceof TicketSystem) {
            $symfonyStyle->error('Ticket system not found: ' . $ticketSystemId);

            return 1;
        }

        $sinceMillis = null;
        if (null !== $since) {
            if (ctype_digit($since)) {
                $sinceMillis = (int) $since;
            } else {
                try {
                    $sinceMillis = new DateTimeImmutable($since)->getTimestamp() * 1000;
                } catch (Exception) {
                    $symfonyStyle->error(sprintf('Invalid --since (expected Y-m-d or epoch milliseconds): %s', $since));

                    return 1;
                }
            }
        }

        $syncRun = $this->syncWorklogsService->sync($system, $sinceMillis, $dryRun);

        $this->syncRunConsoleRenderer->render($symfonyStyle, $syncRun, 'Sync');

        return SyncRunStatus::COMPLETED === $syncRun->getStatus() ? Command::SUCCESS : 1;
    }
}
