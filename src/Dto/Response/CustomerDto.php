<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\Customer;
use App\Entity\Team;
use JsonSerializable;

/**
 * A customer as the v2 admin endpoints and MCP admin tools return it
 * (ADR-022 Phase 3).
 */
final readonly class CustomerDto implements JsonSerializable
{
    /**
     * @param list<int> $teamIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public bool $global,
        public array $teamIds,
    ) {
    }

    public static function fromEntity(Customer $customer): self
    {
        $teamIds = [];
        foreach ($customer->getTeams() as $team) {
            if ($team instanceof Team) {
                $teamIds[] = (int) $team->getId();
            }
        }

        return new self(
            id: (int) $customer->getId(),
            name: (string) $customer->getName(),
            active: (bool) $customer->getActive(),
            global: (bool) $customer->getGlobal(),
            teamIds: $teamIds,
        );
    }

    /**
     * @return array{customer: array{id: int, name: string, active: bool, global: bool, team_ids: list<int>}}
     */
    public function jsonSerialize(): array
    {
        return [
            'customer' => [
                'id' => $this->id,
                'name' => $this->name,
                'active' => $this->active,
                'global' => $this->global,
                'team_ids' => $this->teamIds,
            ],
        ];
    }
}
