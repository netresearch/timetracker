<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Entry;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched for entry-related operations.
 */
class EntryEvent extends Event
{
    public const CREATED = 'entry.created';
    public const UPDATED = 'entry.updated';
    public const DELETED = 'entry.deleted';
    public const SYNCED = 'entry.synced';
    public const SYNC_FAILED = 'entry.sync_failed';

    public function __construct(
        private readonly Entry $entry,
        private readonly ?array $context = null,
    ) {
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }
}