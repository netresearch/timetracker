<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use App\Controller\Api\V2\CreatePersonioRunAction;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Personio\AbsenceImportService;
use App\Service\Personio\AttendanceExportService;
use App\Service\Personio\PersonioRunTrigger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function json_decode;
use function json_encode;

#[AllowMockObjectsWithoutExpectations]
final class CreatePersonioRunActionTest extends TestCase
{
    private AuthorizationCheckerInterface&MockObject $authorizationChecker;
    private AttendanceExportService&MockObject $exportService;
    private AbsenceImportService&MockObject $importService;
    private PersonioRunTrigger $trigger;

    protected function setUp(): void
    {
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->exportService = $this->createMock(AttendanceExportService::class);
        $this->importService = $this->createMock(AbsenceImportService::class);
        $this->exportService->method('exportUser')->willReturn($this->completedRun());
        $this->exportService->method('exportAllOptedIn')->willReturn([$this->completedRun(), $this->completedRun()]);
        $this->importService->method('importUser')->willReturn($this->completedRun());
        $this->importService->method('importAllOptedIn')->willReturn([$this->completedRun()]);

        $this->trigger = new PersonioRunTrigger($this->exportService, $this->importService);
    }

    public function testExportDirectionReturns201WithRuns(): void
    {
        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'export']), new User());

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('runs', $body);
        self::assertIsArray($body['runs']);
        self::assertCount(1, $body['runs']);
    }

    public function testImportDirectionReturns201(): void
    {
        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'import']), new User());

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testUnknownDirectionReturns422(): void
    {
        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'sideways']), new User());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testMissingDirectionReturns422(): void
    {
        $response = $this->action()->__invoke(new Request(), new User());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testUnauthenticatedReturns401(): void
    {
        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'export']), null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAllUsersAsNonAdminReturns403(): void
    {
        $this->authorizationChecker->method('isGranted')->willReturn(false);

        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'export', 'all_users' => true]), new User());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAllUsersAsAdminReturns201WithEveryRun(): void
    {
        $this->authorizationChecker->method('isGranted')->willReturn(true);

        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'export', 'all_users' => true]), new User());

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['runs']);
        self::assertCount(2, $body['runs']);
    }

    public function testInvalidDateReturns422(): void
    {
        $response = $this->action()->__invoke($this->jsonRequest(['direction' => 'export', 'from' => 'not-a-date']), new User());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    private function action(): CreatePersonioRunAction
    {
        return new CreatePersonioRunAction($this->authorizationChecker, $this->trigger);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): Request
    {
        return new Request([], [], [], [], [], [], (string) json_encode($payload));
    }

    private function completedRun(): SyncRun
    {
        return new SyncRun()
            ->setType(SyncRunType::PERSONIO_EXPORT)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable('2026-07-12 10:00:00'));
    }
}
