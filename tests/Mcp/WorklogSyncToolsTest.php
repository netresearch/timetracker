<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogSyncStatus;
use App\Mcp\Tool\GetSyncRunTool;
use App\Mcp\Tool\ListSyncConflictsTool;
use App\Mcp\Tool\ResolveSyncConflictTool;
use App\Mcp\Tool\SyncJiraWorklogsTool;
use App\Service\Sync\ConflictResolutionService;
use App\Service\Sync\SyncWorklogsService;
use App\Service\Sync\VerifyWorklogsService;
use App\ValueObject\Sync\ResolutionResult;
use DateTimeImmutable;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;
use Tests\Traits\CreatesWorklogSyncFixtures;

use function count;

/**
 * MCP worklog-sync tools (ADR-023 §6): trigger runs, inspect a run, list and
 * resolve parked conflicts. The Jira-touching services are mocked in the
 * container; parked states are real DB rows. Fixture user 'unittest' (id 1,
 * admin); 'developer' (id 2, non-admin); ticket system id 1 exists.
 *
 * @internal
 */
final class WorklogSyncToolsTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;
    use CreatesWorklogSyncFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWorklogSyncFixtures();
    }

    public function testSyncToolRejectsMultipleTargets(): void
    {
        $this->useToken(['sync:write']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/single user/');

        $tool = self::getContainer()->get(SyncJiraWorklogsTool::class);
        $tool->syncJiraWorklogs('sync', 1, users: ['developer', 'unittest']);
    }

    public function testSyncToolTriggersVerify(): void
    {
        $verifyMock = $this->createMock(VerifyWorklogsService::class);
        $verifyMock->expects(self::once())
            ->method('verify')
            ->willReturn($this->cannedRun(SyncRunType::VERIFY, $this->admin));
        self::getContainer()->set(VerifyWorklogsService::class, $verifyMock);

        $this->useToken(['sync:write']);

        $result = self::getContainer()->get(SyncJiraWorklogsTool::class)
            ->syncJiraWorklogs(type: 'verify', ticketSystemId: 1);

        self::assertSame('verify', $result['type']);
        self::assertSame('completed', $result['status']);
        self::assertSame(['matched' => 3], $result['counters']);
        self::assertSame('unittest', $result['triggered_by']);
    }

    public function testSyncToolRejectsReadOnlyScope(): void
    {
        $this->useToken(['sync:read']);

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(SyncJiraWorklogsTool::class)
            ->syncJiraWorklogs(type: 'verify', ticketSystemId: 1);
    }

    public function testSyncToolSelfSyncAllowedForNonAdmin(): void
    {
        $syncMock = $this->createMock(SyncWorklogsService::class);
        $syncMock->expects(self::once())
            ->method('syncUser')
            ->with(
                self::callback(static fn (User $target): bool => 'developer' === $target->getUsername()),
                self::callback(static fn (User $tokenOwner): bool => 'developer' === $tokenOwner->getUsername()),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
            )
            ->willReturn($this->cannedRun(SyncRunType::SYNC, $this->developer));
        self::getContainer()->set(SyncWorklogsService::class, $syncMock);

        $this->useToken(['sync:write'], 'developer');

        $result = self::getContainer()->get(SyncJiraWorklogsTool::class)
            ->syncJiraWorklogs(type: 'sync', ticketSystemId: 1);

        self::assertSame('sync', $result['type']);
    }

    public function testSyncToolSyncAnotherUserRequiresAdmin(): void
    {
        $this->useToken(['sync:write'], 'developer');

        $this->expectException(ToolCallException::class);
        self::getContainer()->get(SyncJiraWorklogsTool::class)
            ->syncJiraWorklogs(type: 'sync', ticketSystemId: 1, users: ['unittest']);
    }

    public function testSyncToolSyncTypeAllowedForAdmin(): void
    {
        $syncMock = $this->createMock(SyncWorklogsService::class);
        $syncMock->expects(self::once())
            ->method('syncUser')
            ->with(
                self::callback(static fn (User $target): bool => 'developer' === $target->getUsername()),
                self::callback(static fn (User $tokenOwner): bool => 'unittest' === $tokenOwner->getUsername()),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
            )
            ->willReturn($this->cannedRun(SyncRunType::SYNC, $this->admin));
        self::getContainer()->set(SyncWorklogsService::class, $syncMock);

        $this->useToken(['sync:write']);

        $result = self::getContainer()->get(SyncJiraWorklogsTool::class)
            ->syncJiraWorklogs(type: 'sync', ticketSystemId: 1, users: ['developer']);

        self::assertSame('sync', $result['type']);
    }

    public function testGetSyncRunOwnership(): void
    {
        $developerRun = $this->persistRun($this->developer);
        $adminRun = $this->persistRun($this->admin);

        // Admin sees any run.
        $this->useToken(['sync:read']);
        $seen = self::getContainer()->get(GetSyncRunTool::class)->getSyncRun((int) $developerRun->getId());
        self::assertSame($developerRun->getId(), $seen['id']);

        // Non-admin sees own runs …
        $this->useToken(['sync:read'], 'developer');
        $own = self::getContainer()->get(GetSyncRunTool::class)->getSyncRun((int) $developerRun->getId());
        self::assertSame($developerRun->getId(), $own['id']);
        self::assertSame('developer', $own['triggered_by']);

        // … but not a foreign one.
        $this->expectException(ToolCallException::class);
        self::getContainer()->get(GetSyncRunTool::class)->getSyncRun((int) $adminRun->getId());
    }

    public function testListConflictsForcesSelfForNonAdmin(): void
    {
        $developerState = $this->createSyncState($this->createSyncEntry($this->developer, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $adminState = $this->createSyncState($this->createSyncEntry($this->admin, '2026-06-16'), WorklogSyncStatus::ORPHANED);
        $this->entityManager->flush();

        $this->useToken(['sync:read'], 'developer');

        // A foreign user filter is ignored — non-admins are forced to self.
        $result = self::getContainer()->get(ListSyncConflictsTool::class)->listSyncConflicts(user: 'unittest');

        self::assertIsList($result['conflicts']);
        self::assertSame(count($result['conflicts']), $result['count']);

        $ids = [];
        foreach ($result['conflicts'] as $conflict) {
            self::assertIsArray($conflict);
            self::assertIsArray($conflict['entry']);
            self::assertSame('developer', $conflict['entry']['user']);
            $ids[] = $conflict['id'];
        }

        self::assertContains($developerState->getId(), $ids);
        self::assertNotContains($adminState->getId(), $ids);
    }

    public function testResolveDelegates(): void
    {
        $state = $this->createSyncState($this->createSyncEntry($this->admin, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $this->entityManager->flush();
        $stateId = (int) $state->getId();

        $resolutionMock = $this->createMock(ConflictResolutionService::class);
        $resolutionMock->expects(self::once())
            ->method('resolve')
            ->with(
                self::callback(static fn (WorklogSyncState $candidate): bool => $candidate->getId() === $stateId),
                'local',
                self::callback(static fn (User $actor): bool => 'unittest' === $actor->getUsername()),
            )
            ->willReturn(new ResolutionResult(true, 'pushed_local'));
        self::getContainer()->set(ConflictResolutionService::class, $resolutionMock);

        $this->useToken(['sync:write']);

        $result = self::getContainer()->get(ResolveSyncConflictTool::class)->resolveSyncConflict($stateId, 'local');

        self::assertSame(['resolved' => true, 'action' => 'pushed_local', 'conflict_id' => $stateId], $result);
    }

    public function testResolveFailureThrowsToolCallException(): void
    {
        $state = $this->createSyncState($this->createSyncEntry($this->admin, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $this->entityManager->flush();

        $resolutionMock = $this->createMock(ConflictResolutionService::class);
        $resolutionMock->expects(self::once())
            ->method('resolve')
            ->willReturn(new ResolutionResult(false, '', 'worklog crosses midnight'));
        self::getContainer()->set(ConflictResolutionService::class, $resolutionMock);

        $this->useToken(['sync:write']);

        try {
            self::getContainer()->get(ResolveSyncConflictTool::class)->resolveSyncConflict((int) $state->getId(), 'remote');
            self::fail('expected the failed resolution to surface as a ToolCallException');
        } catch (ToolCallException $toolCallException) {
            self::assertSame('worklog crosses midnight', $toolCallException->getMessage());
        }
    }

    /**
     * An unmanaged run as the mocked services return it (no Jira touched).
     */
    private function cannedRun(SyncRunType $syncRunType, User $user): SyncRun
    {
        return new SyncRun()
            ->setType($syncRunType)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($this->ticketSystem)
            ->setTriggeredBy($user)
            ->setScope([])
            ->setCounters(['matched' => 3])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 10:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 10:00:05'));
    }

    private function persistRun(User $user): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($this->ticketSystem)
            ->setTriggeredBy($user)
            ->setScope([])
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable('2026-07-08 09:00:00'));
        $this->entityManager->persist($syncRun);
        $this->entityManager->flush();

        return $syncRun;
    }
}
