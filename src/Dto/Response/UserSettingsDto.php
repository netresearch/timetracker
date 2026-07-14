<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\User;

/**
 * Wire shape of GET/PATCH /api/v2/settings: the authenticated user's
 * account settings (the fields the settings page's Account and Sync
 * sections read and write).
 */
final readonly class UserSettingsDto
{
    public function __construct(
        public string $locale,
        public bool $show_empty_line,
        public bool $suggest_time,
        public bool $show_future,
        public int $min_entry_duration,
        public bool $personio_sync_enabled,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            locale: $user->getLocale(),
            show_empty_line: $user->getShowEmptyLine(),
            suggest_time: $user->getSuggestTime(),
            show_future: $user->getShowFuture(),
            min_entry_duration: $user->getMinEntryDuration(),
            personio_sync_enabled: $user->getPersonioSyncEnabled(),
        );
    }
}
