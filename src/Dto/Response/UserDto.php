<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\Team;
use App\Entity\User;
use JsonSerializable;

/**
 * A user as the v2 admin endpoints and MCP admin tools return it
 * (ADR-022 Phase 3).
 */
final readonly class UserDto implements JsonSerializable
{
    /**
     * @param list<int> $teamIds
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $abbr,
        public string $type,
        public bool $active,
        public array $teamIds,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        $teamIds = [];
        foreach ($user->getTeams() as $team) {
            if ($team instanceof Team) {
                $teamIds[] = (int) $team->getId();
            }
        }

        return new self(
            id: (int) $user->getId(),
            username: (string) $user->getUsername(),
            abbr: (string) $user->getAbbr(),
            type: $user->getType()->value,
            active: $user->getActive(),
            teamIds: $teamIds,
        );
    }

    /**
     * @return array{user: array{id: int, username: string, abbr: string, type: string, active: bool, team_ids: list<int>}}
     */
    public function jsonSerialize(): array
    {
        return [
            'user' => [
                'id' => $this->id,
                'username' => $this->username,
                'abbr' => $this->abbr,
                'type' => $this->type,
                'active' => $this->active,
                'team_ids' => $this->teamIds,
            ],
        ];
    }
}
