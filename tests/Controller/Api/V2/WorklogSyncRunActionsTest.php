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

    public function testWhitespaceOnlyDateStringsRejected(): void
    {
        $this->postJson([
            'type' => 'verify',
            'ticket_system_id' => 1,
            'from' => '   ',
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

    public function testListReturnsRunsNewestFirst(): void
    {
        $older = $this->seedRun('unittest', '2026-07-01 10:00:00');
        $newer = $this->seedRun('unittest', '2026-07-02 10:00:00');

        $status = $this->requestWithToken('/api/v2/worklog-sync/runs', $this->mintToken(['sync:read']));

        self::assertSame(200, $status);
        $data = $this->responseData();
        $runs = $data['runs'];
        self::assertIsList($runs);
        self::assertCount(2, $runs);
        self::assertSame(2, $data['count']);

        $first = $runs[0];
        $second = $runs[1];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame($newer->getId(), $first['id']);
        self::assertSame($older->getId(), $second['id']);
        self::assertArrayNotHasKey('items', $first);
    }

    public function testListFiltersByTicketSystem(): void
    {
        $otherTicketSystem = new TicketSystem();
        $otherTicketSystem->setName('otherSystem');
        $otherTicketSystem->setUrl('https://other.example.com');
        $otherTicketSystem->setTicketUrl('https://other.example.com/browse/{ticket}');
        $otherTicketSystem->setLogin('other');
        $otherTicketSystem->setPassword('other');
        $this->entityManager->persist($otherTicketSystem);
        $this->entityManager->flush();

        $onDefault = $this->seedRun('unittest', '2026-07-01 10:00:00');
        $onOther = $this->seedRun('unittest', '2026-07-02 10:00:00', $otherTicketSystem);

        $status = $this->requestWithToken(
            sprintf('/api/v2/worklog-sync/runs?ticket_system_id=%d', (int) $otherTicketSystem->getId()),
            $this->mintToken(['sync:read']),
        );

        self::assertSame(200, $status);
        $ids = $this->runIds($this->responseData()['runs']);
        self::assertContains($onOther->getId(), $ids);
        self::assertNotContains($onDefault->getId(), $ids);
    }

    public function testListRequiresReadScope(): void
    {
        $status = $this->requestWithToken('/api/v2/worklog-sync/runs', $this->mintToken(['sync:write']));

        self::assertSame(403, $status);
    }

    public function testListNonAdminSeesOnlyOwnRuns(): void
    {
        $ownRun = $this->seedRun('developer', '2026-07-02 10:00:00');
        $adminRun = $this->seedRun('unittest', '2026-07-01 10:00:00');

        $this->logInSession('developer');
        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/runs');

        $this->assertStatusCode(200);
        $ids = $this->runIds($this->responseData()['runs']);
        self::assertContains($ownRun->getId(), $ids);
        self::assertNotContains($adminRun->getId(), $ids);
    }

    public function testListRespectsLimit(): void
    {
        $this->seedRun('unittest', '2026-07-01 10:00:00');
        $this->seedRun('unittest', '2026-07-02 10:00:00');

        $status = $this->requestWithToken('/api/v2/worklog-sync/runs?limit=1', $this->mintToken(['sync:read']));

        self::assertSame(200, $status);
        $runs = $this->responseData()['runs'];
        self::assertIsList($runs);
        self::assertCount(1, $runs);
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

    private function seedRun(string $username, string $startedAt, ?TicketSystem $ticketSystem = null): SyncRun
    {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setTicketSystem($ticketSystem ?? $this->ticketSystem())
            ->setTriggeredBy($this->user($username))
            ->setScope([])
            ->setCounters([])
            ->setStartedAt(new DateTimeImmutable($startedAt));
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

    /**
     * @return array<string, mixed>
     */
    private function responseData(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> */
        return $data;
    }

    /**
     * @return list<int>
     */
    private function runIds(mixed $runs): array
    {
        self::assertIsArray($runs);

        $ids = [];
        foreach ($runs as $run) {
            self::assertIsArray($run);
            self::assertIsInt($run['id']);
            $ids[] = $run['id'];
        }

        return $ids;
    }

    private function user(string $username): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        assert($user instanceof User);

        return $user;
    }
}
