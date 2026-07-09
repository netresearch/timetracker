<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Service\Sync\ImportWorklogsService;
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

use function is_string;
use function sprintf;

#[AsCommand(name: 'tt:import-worklogs', description: 'Import Jira worklogs as pre-synced entries (ADR-023)')]
class TtImportWorklogsCommand extends Command
{
    public function __construct(
        private readonly ImportWorklogsService $importWorklogsService,
        private readonly ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    /**
     * @param list<string> $users
     */
    public function __invoke(
        #[Argument(description: 'TimeTracker username whose Jira token performs the read', name: 'username')]
        string $username,
        #[Argument(description: 'Ticket system ID', name: 'ticket-system')]
        string $ticketSystem,
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Start date (Y-m-d); default: first day of current month', name: 'from')]
        ?string $from = null,
        #[Option(description: 'End date (Y-m-d); default: today', name: 'to')]
        ?string $to = null,
        #[Option(description: 'Only import for these TT usernames (repeatable); default: everyone (shadow users created for unknowns)', name: 'user')]
        array $users = [],
        #[Option(description: 'Activity ID assigned to imported entries (required)', name: 'default-activity')]
        ?string $defaultActivity = null,
        #[Option(description: 'Preview only: counters and parked items, no writes', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        if (null === $defaultActivity || '' === $defaultActivity) {
            $symfonyStyle->error('--default-activity=<ID> is required (activity assigned to imported entries)');

            return 1;
        }

        $user = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            $symfonyStyle->error('User not found: ' . $username);

            return 1;
        }

        $system = $this->managerRegistry->getRepository(TicketSystem::class)->find((int) $ticketSystem);
        if (!$system instanceof TicketSystem) {
            $symfonyStyle->error('Ticket system not found: ' . $ticketSystem);

            return 1;
        }

        try {
            $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('first day of this month');
            $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today');
        } catch (Exception) {
            $symfonyStyle->error(sprintf('Invalid date in --from/--to (expected Y-m-d): %s / %s', $from ?? '-', $to ?? '-'));

            return 1;
        }

        $syncRun = $this->importWorklogsService->import($user, $system, $fromDate, $toDate, (int) $defaultActivity, $users, $dryRun);

        $this->render($symfonyStyle, $syncRun);

        return SyncRunStatus::COMPLETED === $syncRun->getStatus() ? Command::SUCCESS : 1;
    }

    private function render(SymfonyStyle $symfonyStyle, SyncRun $syncRun): void
    {
        $scope = $syncRun->getScope();
        $scopeFrom = $scope['from'] ?? null;
        $scopeTo = $scope['to'] ?? null;

        $symfonyStyle->section(sprintf(
            'Import run #%d — %s (%s to %s)%s',
            $syncRun->getId() ?? 0,
            $syncRun->getStatus()->value,
            is_string($scopeFrom) ? $scopeFrom : '?',
            is_string($scopeTo) ? $scopeTo : '?',
            true === ($scope['dry_run'] ?? false) ? ' [dry-run]' : '',
        ));

        $rows = [];
        foreach ($syncRun->getCounters() as $key => $count) {
            $rows[] = [$key, $count];
        }

        $symfonyStyle->table(['result', 'count'], $rows);

        foreach ($syncRun->getItems() as $item) {
            $symfonyStyle->writeln(sprintf(
                ' <comment>%-22s</comment> %s %s %s',
                $item->getKind()->value,
                $item->getIssueKey() ?? '-',
                null !== $item->getRemoteWorklogId() ? '(worklog ' . $item->getRemoteWorklogId() . ')' : '',
                $item->getReason(),
            ));
        }
    }
}
