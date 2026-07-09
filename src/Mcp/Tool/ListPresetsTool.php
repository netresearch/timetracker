<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Preset;
use App\Mcp\ScopeGuard;
use App\Repository\PresetRepository;
use Mcp\Capability\Attribute\McpTool;

use function array_map;
use function array_values;

/**
 * MCP tool: list presets (ADR-022 Phase 4) — templates for bulk_log_time.
 */
final readonly class ListPresetsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private PresetRepository $presetRepository,
    ) {
    }

    /**
     * List booking presets (customer/project/activity/description templates).
     * Use a preset's name or id with `bulk_log_time`.
     *
     * @return array{presets: list<array{id: int, name: string, customer_id: int, project_id: int, activity_id: int, description: string}>}
     */
    #[McpTool(name: 'list_presets', description: 'List booking presets (templates for bulk_log_time).')]
    public function listPresets(): array
    {
        $this->scopeGuard->requireScope('presets:read');

        return ['presets' => array_values(array_map(
            static fn (Preset $preset): array => [
                'id' => (int) $preset->getId(),
                'name' => $preset->getName(),
                'customer_id' => (int) $preset->getCustomerId(),
                'project_id' => $preset->getProjectId(),
                'activity_id' => (int) $preset->getActivityId(),
                'description' => $preset->getDescription(),
            ],
            $this->presetRepository->findAll(),
        ))];
    }
}
