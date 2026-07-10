<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Response\SyncRunDto;
use App\Dto\WorklogSyncRunDto;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Mcp\ScopeGuard;
use App\Service\Sync\SyncRunAuthorization;
use App\Service\Sync\SyncRunRequestMapper;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

/**
 * MCP tool: start a worklog verify/import/sync run against a Jira ticket
 * system (ADR-023 §6, amended). Runs execute inline — the result carries the
 * finished run with its counters and per-worklog findings. Non-admins may
 * verify themselves, import only their own worklogs, and sync only themselves;
 * a PL/admin account may sync a named target under its own token.
 */
final readonly class SyncJiraWorklogsTool
{
    public function __construct(
        private ScopeGuard $scopeGuard,
        private SyncRunAuthorization $syncRunAuthorization,
        private SyncRunRequestMapper $syncRunRequestMapper,
        private ManagerRegistry $managerRegistry,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Start a worklog run: "verify" compares timetracker entries against Jira
     * worklogs without writing anything, "import" pulls Jira worklogs into
     * timetracker entries (set dryRun to preview first), "sync" runs the
     * bidirectional lease-checked sync for a single user under your own token
     * (yourself by default; a PL/admin may sync a named target via users[0]).
     * Items the engine cannot decide on its own are parked and appear in
     * list_sync_conflicts.
     *
     * @param list<string> $users
     *
     * @throws ToolCallException on missing scope/role, unknown ticket system,
     *                           or invalid input
     *
     * @return array<string, mixed> the finished run with counters and findings
     */
    #[McpTool(name: 'sync_jira_worklogs', description: 'Start a worklog run against a Jira ticket system: "verify" (read-only comparison), "import" (Jira → timetracker; dryRun previews), or "sync" (bidirectional, single target under your own token; yourself by default, a named target needs PL/admin). Parked items land in list_sync_conflicts.')]
    public function syncJiraWorklogs(
        #[Schema(description: 'Run type: "verify", "import", or "sync".')]
        string $type,
        #[Schema(description: 'Ticket-system id to run against.', minimum: 1)]
        int $ticketSystemId,
        #[Schema(description: 'Range start as YYYY-MM-DD (default: first day of the current month).')]
        ?string $from = null,
        #[Schema(description: 'Range end as YYYY-MM-DD (default: today).')]
        ?string $to = null,
        #[Schema(description: 'Usernames to import worklogs for (import); or the single target username to sync in users[0] (sync). Non-admins may only pass their own username.')]
        array $users = [],
        #[Schema(description: 'Activity id assigned to imported entries (required for import).', minimum: 1)]
        ?int $defaultActivityId = null,
        #[Schema(description: 'Preview without writing anything (import/sync).')]
        bool $dryRun = false,
    ): array {
        $user = $this->scopeGuard->requireScope('sync:write');

        $worklogSyncRunDto = new WorklogSyncRunDto(
            type: $type,
            ticket_system_id: $ticketSystemId,
            from: $from,
            to: $to,
            users: $users,
            default_activity_id: $defaultActivityId,
            dry_run: $dryRun,
        );
        $violations = $this->validator->validate($worklogSyncRunDto);
        if (count($violations) > 0) {
            throw new ToolCallException((string) $violations->get(0)->getMessage());
        }

        if ('sync' === $type && count($users) > 1) {
            throw new ToolCallException('A sync run targets a single user; pass at most one username in users.');
        }

        if (!$this->syncRunAuthorization->canTrigger($user, false, $type, $users)) {
            // Foreign imports and syncing another target need an administrator account.
            $this->scopeGuard->requireAdminScope('sync:write');
        }

        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($ticketSystemId);
        if (!$ticketSystem instanceof TicketSystem) {
            throw new ToolCallException('Ticket system not found.');
        }

        if ('import' === $type && null === $defaultActivityId) {
            throw new ToolCallException('defaultActivityId is required for import runs.');
        }

        $syncTarget = null;
        if ('sync' === $type && [] !== $users) {
            $syncTarget = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $users[0]]);
            if (!$syncTarget instanceof User) {
                throw new ToolCallException('Target user not found.');
            }
        }

        try {
            [$fromDate, $toDate] = $this->syncRunRequestMapper->parseRange($worklogSyncRunDto);
        } catch (Exception) {
            throw new ToolCallException('Invalid date in from/to (expected Y-m-d).');
        }

        $syncRun = $this->syncRunRequestMapper->dispatch($worklogSyncRunDto, $user, $ticketSystem, $fromDate, $toDate, $syncTarget);

        return SyncRunDto::fromEntity($syncRun)->jsonSerialize();
    }
}
