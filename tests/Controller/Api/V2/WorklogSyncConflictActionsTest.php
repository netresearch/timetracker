<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use App\Service\Sync\ConflictResolutionService;
use App\ValueObject\Sync\ResolutionResult;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\MintsApiTokens;

use function assert;
use function count;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * GET /api/v2/worklog-sync/conflicts and POST
 * /api/v2/worklog-sync/conflicts/{id}/resolve (ADR-023 §6). Parked states are
 * real DB rows; the resolution service is mocked in the container — these
 * tests exercise authorization, ownership, and delegation. Session user
 * 'unittest' (id 1, admin); 'developer' (id 2, non-admin).
 *
 * @internal
 */
final class WorklogSyncConflictActionsTest extends AbstractWebTestCase
{
    use MintsApiTokens;

    private EntityManagerInterface $entityManager;

    private TicketSystem $ticketSystem;

    private Project $project;

    private User $admin;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        assert($ticketSystem instanceof TicketSystem);
        $this->ticketSystem = $ticketSystem;

        $project = $this->entityManager->find(Project::class, 2);
        assert($project instanceof Project);
        $project->setTicketSystem($this->ticketSystem);
        $this->project = $project;

        $admin = $this->entityManager->find(User::class, 1);
        assert($admin instanceof User);
        $this->admin = $admin;

        $developer = $this->entityManager->find(User::class, 2);
        assert($developer instanceof User);
        $this->developer = $developer;

        $this->entityManager->flush();
    }

    public function testListOwnConflictsAsNonAdmin(): void
    {
        $developerState = $this->createState($this->createEntry($this->developer, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $adminState = $this->createState($this->createEntry($this->admin, '2026-06-16'), WorklogSyncStatus::ORPHANED);
        $this->entityManager->flush();

        $this->logInSession('developer');
        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/conflicts');

        $this->assertStatusCode(200);
        $data = $this->responseData();
        self::assertIsList($data['conflicts']);
        self::assertSame(count($data['conflicts']), $data['count']);

        $ids = $this->conflictIds($data['conflicts']);
        self::assertContains($developerState->getId(), $ids);
        self::assertNotContains($adminState->getId(), $ids);

        foreach ($data['conflicts'] as $conflict) {
            self::assertIsArray($conflict);
            self::assertIsArray($conflict['entry']);
            self::assertSame('developer', $conflict['entry']['user']);
        }
    }

    public function testAdminSeesAllAndCanFilterByUser(): void
    {
        $developerState = $this->createState($this->createEntry($this->developer, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $adminState = $this->createState($this->createEntry($this->admin, '2026-06-16'), WorklogSyncStatus::CONFLICT);
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/conflicts');

        $this->assertStatusCode(200);
        $ids = $this->conflictIds($this->responseData()['conflicts']);
        self::assertContains($developerState->getId(), $ids);
        self::assertContains($adminState->getId(), $ids);

        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/conflicts?user=developer');

        $this->assertStatusCode(200);
        $ids = $this->conflictIds($this->responseData()['conflicts']);
        self::assertContains($developerState->getId(), $ids);
        self::assertNotContains($adminState->getId(), $ids);
    }

    public function testAdminFilterByUnknownUserRejected(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/worklog-sync/conflicts?user=no-such-user');

        $this->assertStatusCode(422);
    }

    public function testListRequiresReadScope(): void
    {
        $status = $this->requestWithToken(
            '/api/v2/worklog-sync/conflicts',
            $this->mintToken(['sync:write']),
        );

        self::assertSame(403, $status);
    }

    public function testResolveDelegatesAndReturnsAction(): void
    {
        $state = $this->createState($this->createEntry($this->admin, '2026-06-15'), WorklogSyncStatus::CONFLICT);
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

        $this->postResolve($stateId, ['winner' => 'local']);

        $this->assertStatusCode(200);
        $data = $this->responseData();
        self::assertTrue($data['resolved']);
        self::assertSame('pushed_local', $data['action']);
        self::assertSame($stateId, $data['conflict_id']);
    }

    public function testResolveForeignConflict403ForNonAdmin(): void
    {
        $state = $this->createState($this->createEntry($this->admin, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $this->entityManager->flush();

        $this->logInSession('developer');
        $this->postResolve((int) $state->getId(), ['winner' => 'local']);

        $this->assertStatusCode(403);
    }

    public function testResolveUnknown404(): void
    {
        $this->postResolve(999999, ['winner' => 'local']);

        $this->assertStatusCode(404);
    }

    public function testResolveFailureReturns422WithReason(): void
    {
        $state = $this->createState($this->createEntry($this->admin, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $this->entityManager->flush();

        $resolutionMock = $this->createMock(ConflictResolutionService::class);
        $resolutionMock->expects(self::once())
            ->method('resolve')
            ->willReturn(new ResolutionResult(false, '', 'worklog crosses midnight'));
        self::getContainer()->set(ConflictResolutionService::class, $resolutionMock);

        $this->postResolve((int) $state->getId(), ['winner' => 'remote']);

        $this->assertStatusCode(422);
        $data = $this->responseData();
        self::assertSame('worklog crosses midnight', $data['message']);
    }

    public function testResolveRequiresWriteScope(): void
    {
        $state = $this->createState($this->createEntry($this->admin, '2026-06-15'), WorklogSyncStatus::CONFLICT);
        $this->entityManager->flush();

        $status = $this->postJsonWithToken(
            sprintf('/api/v2/worklog-sync/conflicts/%d/resolve', (int) $state->getId()),
            $this->mintToken(['sync:read']),
            ['winner' => 'local'],
        );

        self::assertSame(403, $status);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function postResolve(int $id, array $json): void
    {
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/api/v2/worklog-sync/conflicts/%d/resolve', $id),
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($json, JSON_THROW_ON_ERROR),
        );
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
    private function conflictIds(mixed $conflicts): array
    {
        self::assertIsArray($conflicts);

        $ids = [];
        foreach ($conflicts as $conflict) {
            self::assertIsArray($conflict);
            self::assertIsInt($conflict['id']);
            $ids[] = $conflict['id'];
        }

        return $ids;
    }

    private function createEntry(User $user, string $day): Entry
    {
        $entry = new Entry()
            ->setUser($user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime($day))->setStart('09:00:00')->setEnd('10:00:00');
        $entry->setDuration(60);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function createState(Entry $entry, WorklogSyncStatus $status): WorklogSyncState
    {
        $state = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($this->ticketSystem)
            ->setStatus($status)
            ->setLastSyncedAt(new DateTimeImmutable('2026-07-01 08:00:00'));
        $this->entityManager->persist($state);

        return $state;
    }
}
