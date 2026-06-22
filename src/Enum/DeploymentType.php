<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Ticket system deployment type.
 *
 * Orthogonal to TicketSystemType ("what product"): this discriminates the
 * transport/auth ("how we reach it"). A JIRA system is SERVER (OAuth 1.0a /
 * RSA, Server/DC) or CLOUD (OAuth 2.0 / 3LO, api.atlassian.com).
 */
enum DeploymentType: string
{
    case SERVER = 'SERVER';
    case CLOUD = 'CLOUD';

    /**
     * Get display name for this deployment type.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::SERVER => 'Server / Data Center',
            self::CLOUD => 'Cloud',
        };
    }

    /**
     * Get all available deployment types.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }
}
