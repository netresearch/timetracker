<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Sync\ImportWorklogsService;
use App\Service\Sync\VerifyWorklogsService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\MintsApiTokens;

use function assert;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * POST /api/v2/worklog-sync/runs and GET /api/v2/worklog-sync/runs/{id}
 * (ADR-023 §6). The Jira-touching services are mocked in the container —
 * these tests exercise authorization, validation, and the response shape.
 * Session user 'unittest' (id 1, admin); 'developer' (id 2, non-admin).
 *
 * @internal
 */
final class WorklogSyncRunActionsTest extends AbstractWebTestCase
{
    use MintsApiTokens;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testCreateVerifyRunReturnsRunBody(): void
    {
        $verifyMock = $this->createMock(VerifyWorklogsService::class);
        $verifyMock->expects(self::once())
            ->method('verify')
            ->willReturn($this->cannedRun(SyncRunType::VERIFY, 'unittest'));
        self::getContainer()->set(VerifyWorklogsService::class, $verifyMock);

        $status = $this->postJsonWithToken(
            '/api/v2/worklog-sync/runs',
            $this->mintToken(['sync:write']),
            ['type' => 'verify', 'ticket_system_id' => 1],
        );

        self::assertSame(201, $status);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('verify', $data['type']);
        self::assertSame('completed', $data['status']);
        self::assertSame(['matched' => 3], $data['counters']);
        self::assertSame('unittest', $data['triggered_by']);
    }

    public function testCreateRunRequiresWriteScope(): void
    {
        $status = $this->postJsonWithToken(
            '/api/v2/worklog-sync/runs',
            $this->mintToken(['sync:read']),
            ['type' => 'verify', 'ticket_system_id' => 1],
        );

        self::assertSame(403, $status);
    }

    public function testNonAdminCannotTriggerSync(): void
    {
        $this->logInSession('developer');

        $this->postJson(['type' => 'sync', 'ticket_system_id' => 1]);

        $this->assertStatusCode(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Admin role required for sync runs.', $data['message']);
    }

    public function testNonAdminSelfImportAllowed(): void
    {
        $importMock = $this->createMock(ImportWorklogsService::class);
        $importMock->expects(self::once())
            ->method('import')
            ->willReturn($this->cannedRun(SyncRunType::IMPORT, 'developer'));
        self::getContainer()->set(ImportWorklogsService::class, $importMock);

        $this->logInSession('developer');

        $this->postJson([
            'type' => 'import',
            'ticket_system_id' => 1,
            'users' => ['developer'],
            'default_activity_id' => 1,
        ]);

        $this->assertStatusCode(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('import', $data['type']);
    }

    public function testNonAdminForeignImportForbidden(): void
    {
        $this->logInSession('developer');

        $this->postJson([
            'type' => 'import',
            'ticket_system_id' => 1,
            'users' => ['unittest'],
            'default_activity_id' => 1,
        ]);

        $this->assertStatusCode(403);
    }

    public function testImportWithoutActivityRejected(): void
    {
        $this->postJson([
            'type' => 'import',
            'ticket_system_id' => 1,
            'users' => ['unittest'],
        ]);

        $this->assertStatusCode(422);
    }

    public function testUnknownTicketSystem404(): void
    {
        $this->postJson(['type' => 'verify', 'ticket_system_id' => 99999]);

        $this->assertStatusCode(404);
    }

    public function testInvalidDate422(): void
    {
        $this->postJson([
            'type' => 'verify',
            'ticket_system_id' => 1,
            'from' => 'not-a-date',
        ]);

        $this->assertStatusCode(422);
    }

    public function testEmptyDateStringsRejected(): void
    {
        $this->postJson([
            'type' => 'verify',
            'ticket_system_id' => 1,
            'from' => '',
        ]);

        $this->assertStatusCode(422);
    }

    public function testGetRunReturnsBody(): void
    {
        $run = $this->persistRun('developer');

        $this->logInSession('developer');
        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/worklog-sync/runs/%d', (int) $run->getId()));

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame($run->getId(), $data['id']);
        self::assertSame('verify', $data['type']);
        self::assertSame('developer', $data['triggered_by']);
        self::assertIsList($data['items']);
    }

    public function testGetRunForeign403ForNonAdmin(): void
    {
        $run = $this->persistRun('unittest');

        $this->logInSession('developer');
        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/worklog-sync/runs/%d', (int) $run->getId()));

        $this->assertStatusCode(403);
    }

    public function testGetRunAdminSeesAll(): void
    {
        $run = $this->persistRun('developer');

        $this->client->request(Request::METHOD_GET, sprintf('/api/v2/worklog-sync/runs/%d', (int) $run->getId()));

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame($run->getId(), $data['id']);
    }

    public function testGetRunUnknown404(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/runs/999999');

        $this->assertStatusCode(404);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function postJson(array $json): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/api/v2/worklog-sync/runs',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * An unmanaged run as the mocked services return it (no Jira touched).
     */
    private function cannedRun(SyncRunType $syncRunType, string $username): SyncRun
    {
        return new SyncRun()
            ->setType($syncRunType)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($this->ticketSystem())
            ->setTriggeredBy($this->user($username))
            ->setScope([])
            ->setCounters(['matched' => 3])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 10:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 10:00:05'));
    }

    private function persistRun(string $username): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($this->ticketSystem())
            ->setTriggeredBy($this->user($username))
            ->setScope([])
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable('2026-07-08 09:00:00'));
        $this->entityManager->persist($syncRun);
        $this->entityManager->flush();

        return $syncRun;
    }

    private function ticketSystem(): TicketSystem
    {
        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        assert($ticketSystem instanceof TicketSystem);

        return $ticketSystem;
    }

    private function user(string $username): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        assert($user instanceof User);

        return $user;
    }
}
