<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Distinguishes who performed the labour recorded by an entry: a human or an agent.
 */
enum EntrySource: string
{
    case HUMAN = 'human';
    case AGENT = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::HUMAN => 'Human',
            self::AGENT => 'Agent',
        };
    }

    public static function Valid(string $value): bool
    {
        return null !== self::tryFrom($value);
    }
}
