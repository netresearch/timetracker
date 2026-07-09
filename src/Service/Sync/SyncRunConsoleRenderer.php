<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\SyncRun;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Console rendering shared by the sync-run commands: counters table plus item lines.
 */
class SyncRunConsoleRenderer
{
    public function render(SymfonyStyle $symfonyStyle, SyncRun $syncRun, string $label): void
    {
        $scope = $syncRun->getScope();
        $scopeFrom = $scope['from'] ?? null;
        $scopeTo = $scope['to'] ?? null;

        $symfonyStyle->section(sprintf(
            '%s run #%d — %s (%s to %s)%s',
            $label,
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
