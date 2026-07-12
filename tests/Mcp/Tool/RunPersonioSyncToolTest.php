<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Mcp\Tool;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Mcp\Tool\RunPersonioSyncTool;
use App\Repository\UserRepository;
use App\Service\Personio\AbsenceImportService;
use App\Service\Personio\AttendanceExportService;
use DateTimeImmutable;
use Mcp\Exception\ToolCallException;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

/**
 * MCP Personio run tool (ADR-024 P3). The Personio services are mocked in the
 * container; these exercise the direction/scope/role gate and the response
 * shape. Fixture user 'unittest' (id 1, admin); 'developer' (id 2, non-admin).
 *
 * @internal
 *
 * @coversNothing
 */
final class RunPersonioSyncToolTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    public function testExportSelfReturnsRun(): void
    {
        $mock = $this->createMock(AttendanceExportService::class);
        $mock->expects(self::once())->method('exportUser')->willReturn($this->completedRun(SyncRunType::PERSONIO_EXPORT));
        self::getContainer()->set(AttendanceExportService::class, $mock);

        $this->useToken(['sync:write'], 'developer');

        $result = $this->tool()->runPersonioSync('export');

        self::assertIsArray($result['runs']);
        self::assertCount(1, $result['runs']);
    }

    public function testImportSelfReturnsRun(): void
    {
        $mock = $this->createMock(AbsenceImportService::class);
        $mock->expects(self::once())->method('importUser')->willReturn($this->completedRun(SyncRunType::PERSONIO_IMPORT));
        self::getContainer()->set(AbsenceImportService::class, $mock);

        $this->useToken(['sync:write'], 'developer');

        $result = $this->tool()->runPersonioSync('import');

        self::assertIsArray($result['runs']);
        self::assertCount(1, $result['runs']);
    }

    public function testUnknownDirectionIsRejected(): void
    {
        $this->useToken(['sync:write'], 'developer');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/direction/');

        $this->tool()->runPersonioSync('sideways');
    }

    public function testAllUsersAsNonAdminIsRejected(): void
    {
        $this->useToken(['sync:write'], 'developer');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/administrator/');

        $this->tool()->runPersonioSync('export', allUsers: true);
    }

    public function testAllUsersAsAdminRunsForEveryone(): void
    {
        $mock = $this->createMock(AttendanceExportService::class);
        $mock->expects(self::once())->method('exportAllOptedIn')
            ->willReturn([$this->completedRun(SyncRunType::PERSONIO_EXPORT), $this->completedRun(SyncRunType::PERSONIO_EXPORT)]);
        self::getContainer()->set(AttendanceExportService::class, $mock);

        $this->useToken(['sync:write'], 'unittest');

        $result = $this->tool()->runPersonioSync('export', allUsers: true);

        self::assertIsArray($result['runs']);
        self::assertCount(2, $result['runs']);
    }

    public function testReadOnlyScopeIsRejected(): void
    {
        $this->useToken(['sync:read'], 'unittest');

        $this->expectException(ToolCallException::class);

        $this->tool()->runPersonioSync('export');
    }

    private function tool(): RunPersonioSyncTool
    {
        $tool = self::getContainer()->get(RunPersonioSyncTool::class);
        self::assertInstanceOf(RunPersonioSyncTool::class, $tool);

        return $tool;
    }

    private function completedRun(SyncRunType $type): SyncRun
    {
        $repository = self::getContainer()->get(UserRepository::class);
        $user = $repository->findOneBy(['username' => 'unittest']);
        self::assertInstanceOf(User::class, $user);

        return new SyncRun()
            ->setType($type)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTriggeredBy($user)
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable('2026-07-12 10:00:00'));
    }
}
