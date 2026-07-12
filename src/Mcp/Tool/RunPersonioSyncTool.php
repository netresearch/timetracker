<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\SyncRunDto;
use App\Entity\SyncRun;
use App\Mcp\ScopeGuard;
use App\Service\Personio\PersonioRunTrigger;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

use function array_map;

/**
 * MCP tool: run the Personio export or import on demand (ADR-024 P3). `direction`
 * selects `export` (attendances) or `import` (absences). It runs for the caller
 * by default; allUsers runs the cron path for every opted-in, mapped user and
 * needs an administrator account. Runs execute inline — the result carries the
 * finished run(s) with counters and parked items.
 */
final readonly class RunPersonioSyncTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private PersonioRunTrigger $personioRunTrigger,
    ) {
    }

    /**
     * Run the Personio sync. direction "export" pushes TimeTracker worklogs to
     * Personio as attendances (dryRun previews); "import" pulls Personio absences
     * (vacation/sick) into TimeTracker entries. Runs for you by default; set
     * allUsers to run for every opted-in, mapped user (requires an administrator).
     *
     * @throws ToolCallException on missing scope/role, an unknown direction, or an invalid date
     *
     * @return array{runs: list<array<string, mixed>>}
     */
    #[McpTool(name: 'run_personio_sync', description: 'Run the Personio sync: direction "export" (TimeTracker → Personio attendances; dryRun previews) or "import" (Personio absences → TimeTracker). Runs for you by default; allUsers runs for everyone opted-in (admin only).')]
    public function runPersonioSync(
        #[Schema(description: 'Run direction: "export" (attendances) or "import" (absences).')]
        string $direction,
        #[Schema(description: 'Range start as YYYY-MM-DD (export default: 14 days ago; import default: 30 days ago).')]
        ?string $from = null,
        #[Schema(description: 'Range end as YYYY-MM-DD (export default: today; import default: 90 days ahead).')]
        ?string $to = null,
        #[Schema(description: 'Preview without writing (export only).')]
        bool $dryRun = false,
        #[Schema(description: 'Run for every opted-in, mapped user (requires an administrator account).')]
        bool $allUsers = false,
    ): array {
        if (!PersonioRunTrigger::isDirection($direction)) {
            throw new ToolCallException('direction must be "export" or "import".');
        }

        $user = $allUsers
            ? $this->scopeGuard->requireAdminScope('sync:write')
            : $this->scopeGuard->requireScope('sync:write');

        try {
            $runs = $this->personioRunTrigger->run($direction, $user, $allUsers, $from, $to, $dryRun);
        } catch (Exception) {
            throw new ToolCallException('Invalid date in from/to (expected Y-m-d).');
        }

        return ['runs' => array_map(static fn (SyncRun $syncRun): array => SyncRunDto::fromEntity($syncRun)->jsonSerialize(), $runs)];
    }
}
