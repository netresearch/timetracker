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
use App\Entity\UserTicketsystem;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Sync\ImportWorklogsService;
use App\Service\Sync\SyncWorklogsService;
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

    public function testCreateSyncRunSyncsSelf(): void
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
            ->willReturn($this->cannedRun(SyncRunType::SYNC, 'developer'));
        self::getContainer()->set(SyncWorklogsService::class, $syncMock);

        $this->logInSession('developer');

        $this->postJson(['type' => 'sync', 'ticket_system_id' => 1]);

        $this->assertStatusCode(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('sync', $data['type']);
    }

    public function testAdminCanSyncAnotherUserUnderOwnToken(): void
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
            ->willReturn($this->cannedRun(SyncRunType::SYNC, 'unittest'));
        self::getContainer()->set(SyncWorklogsService::class, $syncMock);

        $this->postJson(['type' => 'sync', 'ticket_system_id' => 1, 'users' => ['developer']]);

        $this->assertStatusCode(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('sync', $data['type']);
    }

    public function testNonAdminCannotSyncAnotherUser(): void
    {
        $this->logInSession('developer');

        $this->postJson(['type' => 'sync', 'ticket_system_id' => 1, 'users' => ['unittest']]);

        $this->assertStatusCode(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Admin role required to sync another user.', $data['message']);
    }

    public function testGetPreferences(): void
    {
        $this->connectUser('developer', syncEnabled: true);

        $this->logInSession('developer');
        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/preferences');

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertFalse($data['can_sync_all']);
        self::assertIsList($data['preferences']);
        self::assertCount(1, $data['preferences']);
        $preference = $data['preferences'][0];
        self::assertIsArray($preference);
        self::assertSame(1, $preference['ticket_system_id']);
        self::assertTrue($preference['sync_enabled']);
        self::assertFalse($preference['sync_all']);
    }

    public function testPutPreferencesSelf(): void
    {
        $connectionId = (int) $this->connectUser('developer')->getId();

        $this->logInSession('developer');
        $this->putJson(['ticket_system_id' => 1, 'sync_enabled' => true]);

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['sync_enabled']);
        self::assertTrue($this->reloadConnection($connectionId)->getSyncEnabled());
    }

    public function testPutSyncAllRequiresPl(): void
    {
        $developerId = (int) $this->connectUser('developer')->getId();

        $this->logInSession('developer');
        $this->putJson(['ticket_system_id' => 1, 'sync_enabled' => true, 'sync_all' => true]);

        $this->assertStatusCode(403);
        self::assertFalse($this->reloadConnection($developerId)->getSyncAll());

        // A privileged (admin/PL) caller may opt into sync-all.
        $adminId = (int) $this->connectUser('unittest')->getId();

        $this->logInSession('unittest');
        $this->putJson(['ticket_system_id' => 1, 'sync_enabled' => true, 'sync_all' => true]);

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['sync_all']);
        self::assertTrue($this->reloadConnection($adminId)->getSyncAll());
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
     * @param array<string, mixed> $json
     */
    private function putJson(array $json): void
    {
        $this->client->request(
            Request::METHOD_PUT,
            '/api/v2/worklog-sync/preferences',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Connect a fixture user to ticket system 1 with the given opt-in flags.
     */
    private function connectUser(string $username, bool $syncEnabled = false, bool $syncAll = false): UserTicketsystem
    {
        $userTicketsystem = new UserTicketsystem()
            ->setUser($this->user($username))
            ->setTicketSystem($this->ticketSystem())
            ->setAccessToken('token')
            ->setTokenSecret('secret')
            ->setSyncEnabled($syncEnabled)
            ->setSyncAll($syncAll);
        $this->entityManager->persist($userTicketsystem);
        $this->entityManager->flush();

        return $userTicketsystem;
    }

    /**
     * Re-read a connection from the database, detaching stale in-memory state
     * left over from the request lifecycle, so persistence is asserted against
     * the row that was actually written.
     */
    private function reloadConnection(int $id): UserTicketsystem
    {
        $this->entityManager->clear();
        $userTicketsystem = $this->entityManager->find(UserTicketsystem::class, $id);
        self::assertInstanceOf(UserTicketsystem::class, $userTicketsystem);

        return $userTicketsystem;
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
