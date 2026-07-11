<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Interpretation;

use App\Controller\Interpretation\BaseInterpretationController;
use App\Entity\Entry;
use App\Enum\EntrySource;
use PHPUnit\Framework\TestCase;

/**
 * ADR-025 Task 13: the shared controlling sum can slice by source, so a total
 * never folds human and agent labour; null keeps the all-sources behaviour.
 *
 * @internal
 *
 * @coversNothing
 */
final class CalculateSumSourceTest extends TestCase
{
    public function testCalculateSumSlicesBySource(): void
    {
        $summer = new class extends BaseInterpretationController {
            /**
             * @param Entry[] $entries
             */
            public function sum(array $entries, ?EntrySource $source = null): int
            {
                return $this->calculateSum($entries, $source);
            }
        };

        $entries = [
            new Entry()->setSource(EntrySource::HUMAN)->setDuration(60),
            new Entry()->setSource(EntrySource::AGENT)->setDuration(180),
        ];

        self::assertSame(240, $summer->sum($entries), 'no filter sums both (back-compat)');
        self::assertSame(60, $summer->sum($entries, EntrySource::HUMAN), 'human-only excludes the agent 180');
        self::assertSame(180, $summer->sum($entries, EntrySource::AGENT), 'agent-only excludes the human 60');
    }
}
