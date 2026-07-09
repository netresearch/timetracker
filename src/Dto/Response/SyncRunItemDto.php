<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\SyncRunItem;
use JsonSerializable;

use const DATE_ATOM;

/**
 * One finding of a sync run — a divergence, orphan, error, or import note —
 * as the v2 run endpoints and MCP sync tools return it (ADR-023 §6).
 */
final readonly class SyncRunItemDto implements JsonSerializable
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public string $kind,
        public ?string $issueKey,
        public ?int $remoteWorklogId,
        public ?int $entryId,
        public ?string $author,
        public string $reason,
        public ?array $payload,
        public ?string $createdAt,
    ) {
    }

    public static function fromEntity(SyncRunItem $item): self
    {
        return new self(
            kind: $item->getKind()->value,
            issueKey: $item->getIssueKey(),
            remoteWorklogId: $item->getRemoteWorklogId(),
            entryId: $item->getEntry()?->getId(),
            author: $item->getAuthor(),
            reason: $item->getReason(),
            payload: $item->getPayload(),
            createdAt: $item->getCreatedAt()?->format(DATE_ATOM),
        );
    }

    /**
     * @return array{kind: string, issue_key: string|null, remote_worklog_id: int|null, entry_id: int|null, author: string|null, reason: string, payload: array<string, mixed>|null, created_at: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'issue_key' => $this->issueKey,
            'remote_worklog_id' => $this->remoteWorklogId,
            'entry_id' => $this->entryId,
            'author' => $this->author,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'created_at' => $this->createdAt,
        ];
    }
}
