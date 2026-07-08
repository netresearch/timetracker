<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\Project;
use JsonSerializable;

/**
 * A project as the v2 admin endpoints and MCP admin tools return it
 * (ADR-022 Phase 3).
 */
final readonly class ProjectDto implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $customerId,
        public string $jiraId,
        public bool $active,
        public bool $global,
    ) {
    }

    public static function fromEntity(Project $project): self
    {
        return new self(
            id: (int) $project->getId(),
            name: $project->getName(),
            customerId: $project->getCustomer()?->getId(),
            jiraId: (string) $project->getJiraId(),
            active: $project->getActive(),
            global: $project->getGlobal(),
        );
    }

    /**
     * @return array{project: array{id: int, name: string, customer_id: int|null, jira_id: string, active: bool, global: bool}}
     */
    public function jsonSerialize(): array
    {
        return [
            'project' => [
                'id' => $this->id,
                'name' => $this->name,
                'customer_id' => $this->customerId,
                'jira_id' => $this->jiraId,
                'active' => $this->active,
                'global' => $this->global,
            ],
        ];
    }
}
