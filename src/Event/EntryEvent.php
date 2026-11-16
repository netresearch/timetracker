<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Event;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched for entry-related operations.
 */
class EntryEvent extends Event
{
    public const string CREATED = 'entry.created';

    public const string UPDATED = 'entry.updated';

    public const string DELETED = 'entry.deleted';

    public const string SYNCED = 'entry.synced';

    public const string SYNC_FAILED = 'entry.sync_failed';

    /**
     * Ticket systems whose Jira refused the user's token while this event was
     * handled, keyed by id — the save response tells the user to connect them.
     *
     * @var array<int, TicketSystem>
     */
    private array $ticketSystemsNeedingAuthorization = [];

    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        private readonly Entry $entry,
        private readonly ?array $context = null,
    ) {
    }

    public function requireJiraAuthorization(TicketSystem $ticketSystem): void
    {
        $this->ticketSystemsNeedingAuthorization[(int) $ticketSystem->getId()] = $ticketSystem;
    }

    /**
     * @return list<TicketSystem>
     */
    public function getTicketSystemsNeedingAuthorization(): array
    {
        return array_values($this->ticketSystemsNeedingAuthorization);
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }
}
