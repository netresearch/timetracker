<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\SyncRun;
use App\Enum\SyncRunStatus;
use App\Service\Sync\SyncRunConsoleRenderer;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function trim;

/**
 * Shared --from/--to handling and run reporting of the Personio cron commands
 * (import and export take the same options and report the same way).
 */
trait SyncWindowCommandTrait
{
    /**
     * Resolves the day window from the raw --from/--to values, falling back to the given
     * offsets from today; reports the problem and returns null when a date is unusable.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     */
    private function resolveWindow(SymfonyStyle $symfonyStyle, ?string $from, ?string $to, string $defaultFromOffset, string $defaultToOffset): ?array
    {
        if ($this->isBlank($from) || $this->isBlank($to)) {
            $symfonyStyle->error('Invalid date in --from/--to: must not be blank (expected Y-m-d)');

            return null;
        }

        try {
            $fromDate = new DateTimeImmutable($from ?? 'today');
            $toDate = new DateTimeImmutable($to ?? 'today');
        } catch (Exception) {
            $symfonyStyle->error(sprintf('Invalid date in --from/--to (expected Y-m-d): %s / %s', $from ?? '-', $to ?? '-'));

            return null;
        }

        return [
            null !== $from ? $fromDate : $fromDate->modify($defaultFromOffset),
            null !== $to ? $toDate : $toDate->modify($defaultToOffset),
        ];
    }

    /**
     * Renders every run and returns the command exit code: failure when any run failed.
     *
     * @param list<SyncRun> $runs
     */
    private function renderRuns(SymfonyStyle $symfonyStyle, SyncRunConsoleRenderer $syncRunConsoleRenderer, array $runs, string $label): int
    {
        $failed = false;
        foreach ($runs as $syncRun) {
            $syncRunConsoleRenderer->render($symfonyStyle, $syncRun, $label);
            if (SyncRunStatus::FAILED === $syncRun->getStatus()) {
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function isBlank(?string $value): bool
    {
        return null !== $value && '' === trim($value);
    }
}
