# Jira Worklog Sync — Phase 2: Import (Author Mapping, Shadow Users, Entry Creation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import Jira worklogs into TimeTracker as pre-synced entries — first-time self-import and PO import for non-TT users (ADR-023 use cases 1 + 3) — via a `tt:import-worklogs` console command with dry-run preview.

**Architecture:** Per [ADR-023](../../adr/ADR-023-jira-worklog-bidirectional-sync.md) §2 (unmatched remote = import) and §3 (auto-match + shadow users). Builds on Phase 1 (PR #590): `SyncRun`/`SyncRunItem`/`WorklogSyncState`, `RemoteWorklogNormalizer`, Jira read methods. New pieces: `remote_account_id` mapping column on `users_ticket_systems`, a `JiraAuthorMapper` (match by Jira author name / email-localpart against TT `username` — **User has no email column**, ADR §3's "username/email" resolves to this), shadow users as `active=false` rows, a `TicketProjectResolver` (subticket-exact-over-prefix, per ADR-020 precedence), and an `ImportWorklogsService` that creates entries **pre-marked synced with no `EntryEvent` dispatch** (the push subscriber only reacts to dispatched events, never Doctrine lifecycle — verified) plus a `WorklogSyncState` base row each. Day-render classes are recalculated via a `DayClassService` extracted verbatim from `BaseTrackingController::calculateClasses`.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 + Migrations, PHPUnit 13, PHPStan level 10, Rector.

## Global Constraints

- Every new PHP file starts with this exact header, then `declare(strict_types=1);`:
  ```php
  <?php

  /*
   * Copyright (c) 2026 Netresearch DTT GmbH
   * SPDX-License-Identifier: AGPL-3.0-only
   */

  declare(strict_types=1);
  ```
- All commands run inside the dev container: `docker compose --profile dev exec app-dev <cmd>`.
- Quality gates before EVERY commit — all four tools: `composer analyze` (PHPStan level 10), `composer analyze:arch` (phpat), `composer cs-fix`, **`composer rector`** (apply; then re-run cs-fix and the tests — Phase 1's CI failed because rector was skipped locally). Repo cs rules rewrite `(new X())->...` to PHP 8.4 `new X()->...`; accept what cs-fix/rector produce.
- Tests: unit tests must not touch the database; `self::assertSame()`; new unit tests under `tests/Service/...` (already in the `unit` suite); `tests/Command` and `tests/Repository` run in the `integration` suite.
- Commits: conventional format, signed: `git commit -S --signoff -m "..."`. No AI attribution.
- Entities extend `App\Model\Base`, protected typed properties, fluent setters returning `static`, `datetime_immutable` for timestamps.
- `Entry::getDuration()` is **minutes**. `Entry::setTicket()` strips spaces. `Entry::setStart()/setEnd()` accept `DateTimeInterface|string` and call `alignStartAndEnd()`; set `setDuration()` explicitly after start/end.
- Import must NEVER dispatch `EntryEvent` (`App\Event\EntryEvent::CREATED` etc.) — that is the only trigger of the Jira push (`EntryEventSubscriber::getSubscribedEvents`, no Doctrine lifecycle hooks). Assert this in tests by injecting no dispatcher into the import service at all.
- Shadow users: `new User()->setUsername(...)->setActive(false)` — `active=false` blocks login and lead assignment; `password` stays null (never a local account); `type` stays default `UserType::USER`. Minimal NOT NULL requirement is `username` only (all other NOT NULL columns have entity defaults).

---

### Task 1: Migration `remote_account_id` + entity + new item kinds

**Files:**
- Create: `migrations/Version20260709_UserTicketsystemRemoteAccountId.php`
- Modify: `src/Entity/UserTicketsystem.php` (add property + getter/setter after `avoidConnection`)
- Modify: `src/Enum/SyncItemKind.php` (two new cases)
- Modify: `tests/Enum/SyncEnumsTest.php` (extend the kind-values test)
- Maybe modify: `sql/unittest/001_testtables.sql` (see Step 5)

**Interfaces:**
- Consumes: existing `UserTicketsystem` (columns per ADR-017: accesstoken, tokensecret, refresh_token, token_expires_at, avoidconnection; NO unique constraint on (user, ticket_system)).
- Produces: `UserTicketsystem::getRemoteAccountId(): ?string` / `setRemoteAccountId(?string): static`; `SyncItemKind::UNRESOLVED_PROJECT = 'unresolved_project'` and `SyncItemKind::SHADOW_USER_CREATED = 'shadow_user_created'` appended after `PROBABLE_DUPLICATE`.

- [ ] **Step 1: Extend the enum test (failing first)**

In `tests/Enum/SyncEnumsTest.php`, replace the expected list in `testSyncItemKindValues()` with:

```php
self::assertSame(
    ['remote_only', 'local_only', 'never_synced', 'diverged', 'local_dirty', 'remote_dirty', 'mergeable', 'conflict', 'probable_duplicate', 'unresolved_project', 'shadow_user_created', 'truncated', 'error'],
    array_column(SyncItemKind::cases(), 'value'),
);
```

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Enum/SyncEnumsTest.php` — Expected: FAIL (arrays differ).

- [ ] **Step 2: Add the enum cases**

In `src/Enum/SyncItemKind.php`, insert after `case PROBABLE_DUPLICATE = 'probable_duplicate';`:

```php
case UNRESOLVED_PROJECT = 'unresolved_project';
case SHADOW_USER_CREATED = 'shadow_user_created';
```

Re-run the enum test — Expected: PASS.

- [ ] **Step 3: Entity property**

In `src/Entity/UserTicketsystem.php`, after the `avoidConnection` property:

```php
/**
 * Jira account identity (Cloud accountId or Server username) this TT user maps to (ADR-023 §3).
 */
#[ORM\Column(name: 'remote_account_id', type: 'string', length: 255, nullable: true)]
protected ?string $remoteAccountId = null;
```

And with the other getters/setters:

```php
public function getRemoteAccountId(): ?string
{
    return $this->remoteAccountId;
}

public function setRemoteAccountId(?string $remoteAccountId): static
{
    $this->remoteAccountId = $remoteAccountId;

    return $this;
}
```

- [ ] **Step 4: Migration**

`migrations/Version20260709_UserTicketsystemRemoteAccountId.php` (license header, `DoctrineMigrations` namespace, style of `Version20260709_WorklogSyncFoundation.php`):

```php
final class Version20260709_UserTicketsystemRemoteAccountId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023 §3: users_ticket_systems.remote_account_id maps TT users to Jira author identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_ticket_systems ADD remote_account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_uts_remote_account ON users_ticket_systems (ticket_system_id, remote_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_uts_remote_account ON users_ticket_systems');
        $this->addSql('ALTER TABLE users_ticket_systems DROP COLUMN remote_account_id');
    }
}
```

Run: `docker compose --profile dev exec app-dev php bin/console doctrine:migrations:migrate --no-interaction` — applies cleanly.

- [ ] **Step 5: Test-schema parity check**

Run: `grep -n "refresh_token" sql/unittest/001_testtables.sql`.
- If it IS there (test schema tracks migrations by hand), add `remote_account_id varchar(255) DEFAULT NULL,` to the `users_ticket_systems` CREATE TABLE in the same style.
- If it is NOT there, the test DB gets columns via migrations — do nothing.

- [ ] **Step 6: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add -A migrations src/Entity/UserTicketsystem.php src/Enum/SyncItemKind.php tests/Enum/SyncEnumsTest.php sql/unittest/001_testtables.sql
git commit -S --signoff -m "feat(sync): remote account mapping column and import item kinds (ADR-023 §3)"
```

(If `sql/unittest/001_testtables.sql` was untouched in Step 5, drop it from the `git add`.)

---

### Task 2: Repository finders

**Files:**
- Modify: `src/Repository/ProjectRepository.php` (append method)
- Modify: `src/Repository/EntryRepository.php` (append two methods after `findJiraSyncCandidates`)
- Test: `tests/Repository/ImportFindersTest.php` (integration suite)

**Interfaces:**
- Produces:
  - `ProjectRepository::findByTicketSystem(TicketSystem $ticketSystem): array` → `list<Project>`
  - `EntryRepository::findOneByWorklogIdAndTicketSystem(int $worklogId, TicketSystem $ticketSystem): ?Entry` (joins project on the ticket system — worklog ids are unique per Jira instance, not globally)
  - `EntryRepository::findUnlinkedDuplicate(User $user, string $ticket, DateTimeInterface $day, int $durationMinutes): ?Entry` (`worklogId IS NULL`, exact user+ticket+day+duration — ADR-023 probable-duplicate heuristic)

- [ ] **Step 1: Write the failing integration test**

`tests/Repository/ImportFindersTest.php` — fixtures: ticket system id 1 exists; projects 1–3 have `ticket_system = NULL` in seed, so wire one in the test:

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\ProjectRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportFindersTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TicketSystem $ticketSystem;
    private Project $project;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $ticketSystem = $this->entityManager->find(TicketSystem::class, 1);
        \assert($ticketSystem instanceof TicketSystem);
        $this->ticketSystem = $ticketSystem;

        $project = $this->entityManager->find(Project::class, 2);
        \assert($project instanceof Project);
        $project->setTicketSystem($this->ticketSystem);
        $user = $this->entityManager->find(User::class, 2);
        \assert($user instanceof User);
        $this->user = $user;
        $this->project = $project;
        $this->entityManager->flush();
    }

    public function testFindByTicketSystemReturnsLinkedProjects(): void
    {
        $projectRepository = self::getContainer()->get(ProjectRepository::class);
        \assert($projectRepository instanceof ProjectRepository);

        $projects = $projectRepository->findByTicketSystem($this->ticketSystem);

        $ids = array_map(static fn (Project $project): ?int => $project->getId(), $projects);
        self::assertContains(2, $ids);
        self::assertNotContains(1, $ids);
    }

    public function testFindOneByWorklogIdAndTicketSystem(): void
    {
        $entry = (new Entry())
            ->setUser($this->user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime('2026-06-15'))->setStart('09:00:00')->setEnd('10:00:00')
            ->setWorklogId(987654);
        $entry->setDuration(60);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        \assert($entryRepository instanceof EntryRepository);

        $found = $entryRepository->findOneByWorklogIdAndTicketSystem(987654, $this->ticketSystem);
        self::assertSame($entry->getId(), $found?->getId());
        self::assertNull($entryRepository->findOneByWorklogIdAndTicketSystem(111111, $this->ticketSystem));
    }

    public function testFindUnlinkedDuplicate(): void
    {
        $unlinked = (new Entry())
            ->setUser($this->user)->setProject($this->project)->setTicket('TIM-1')
            ->setDay(new DateTime('2026-06-16'))->setStart('09:00:00')->setEnd('10:30:00');
        $unlinked->setDuration(90);
        $this->entityManager->persist($unlinked);
        $this->entityManager->flush();

        $entryRepository = self::getContainer()->get(EntryRepository::class);
        \assert($entryRepository instanceof EntryRepository);

        $hit = $entryRepository->findUnlinkedDuplicate($this->user, 'TIM-1', new DateTime('2026-06-16'), 90);
        self::assertSame($unlinked->getId(), $hit?->getId());
        self::assertNull($entryRepository->findUnlinkedDuplicate($this->user, 'TIM-1', new DateTime('2026-06-16'), 45));
    }
}
```

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Repository/ImportFindersTest.php` — Expected: FAIL, undefined methods. (Uses the `.env.test.local`-safe container run; if DATABASE_URL trouble appears, export it as a real env var per repo docs.)

- [ ] **Step 2: Implement the finders**

`src/Repository/ProjectRepository.php` — append:

```php
/**
 * All projects booking on the given ticket system (ADR-023 import: ticket→project resolution).
 *
 * @return list<Project>
 */
public function findByTicketSystem(TicketSystem $ticketSystem): array
{
    /** @var list<Project> */
    return $this->createQueryBuilder('p')
        ->where('p.ticketSystem = :ticketSystem')
        ->setParameter('ticketSystem', $ticketSystem)
        ->getQuery()
        ->getResult();
}
```

(add `use App\Entity\TicketSystem;` if missing.)

`src/Repository/EntryRepository.php` — append after `findJiraSyncCandidates`:

```php
/**
 * Entry already linked to the given Jira worklog on this ticket system, if any (import dedupe).
 */
public function findOneByWorklogIdAndTicketSystem(int $worklogId, TicketSystem $ticketSystem): ?Entry
{
    /** @var Entry|null */
    return $this->createQueryBuilder('e')
        ->join('e.project', 'p')
        ->where('e.worklogId = :worklogId')
        ->andWhere('p.ticketSystem = :ticketSystem')
        ->setParameter('worklogId', $worklogId)
        ->setParameter('ticketSystem', $ticketSystem)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}

/**
 * Unlinked entry matching user+ticket+day+duration — the ADR-023 probable-duplicate heuristic.
 */
public function findUnlinkedDuplicate(User $user, string $ticket, DateTimeInterface $day, int $durationMinutes): ?Entry
{
    /** @var Entry|null */
    return $this->createQueryBuilder('e')
        ->where('e.user = :user')
        ->andWhere('e.ticket = :ticket')
        ->andWhere('e.day = :day')
        ->andWhere('e.duration = :duration')
        ->andWhere('e.worklogId IS NULL')
        ->setParameter('user', $user)
        ->setParameter('ticket', $ticket)
        ->setParameter('day', $day->format('Y-m-d'))
        ->setParameter('duration', $durationMinutes)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Repository/ImportFindersTest.php` — Expected: PASS (3 tests).

- [ ] **Step 4: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add src/Repository/ProjectRepository.php src/Repository/EntryRepository.php tests/Repository/ImportFindersTest.php
git commit -S --signoff -m "feat(sync): repository finders for worklog import (ADR-023)"
```

---

### Task 3: DayClassService extraction

**Files:**
- Create: `src/Service/Tracking/DayClassService.php`
- Modify: `src/Controller/Tracking/BaseTrackingController.php:47-100` (`calculateClasses` delegates)
- Test: `tests/Service/Tracking/DayClassServiceTest.php`

**Interfaces:**
- Produces: `DayClassService::recalculate(int $userId, string $day): void` — verbatim behavior of the current `BaseTrackingController::calculateClasses` (first entry DAYBREAK; each further entry PAUSE when it starts after the previous end, OVERLAP when before, PLAIN when seamless; only changed entries persisted+flushed; no-op for `userId === 0` or empty day).

- [ ] **Step 1: Write the failing unit test**

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Tracking;

use App\Entity\Entry;
use App\Enum\EntryClass;
use App\Repository\EntryRepository;
use App\Service\Tracking\DayClassService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DayClassService::class)]
#[AllowMockObjectsWithoutExpectations]
final class DayClassServiceTest extends TestCase
{
    /**
     * @param list<Entry> $entries
     */
    private function service(array $entries): DayClassService
    {
        $entryRepository = $this->createMock(EntryRepository::class);
        $entryRepository->method('findByDay')->willReturn($entries);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($entryRepository);
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        return new DayClassService($managerRegistry);
    }

    private function entry(string $start, string $end): Entry
    {
        return (new Entry())->setStart($start)->setEnd($end);
    }

    public function testFirstEntryBecomesDaybreakOthersClassified(): void
    {
        $first = $this->entry('09:00:00', '10:00:00');   // -> DAYBREAK
        $gap = $this->entry('10:30:00', '11:00:00');     // starts after prev end -> PAUSE
        $overlap = $this->entry('10:45:00', '11:30:00'); // starts before prev end -> OVERLAP
        $seamless = $this->entry('11:30:00', '12:00:00'); // seamless -> PLAIN

        $this->service([$first, $gap, $overlap, $seamless])->recalculate(2, '2026-06-15');

        self::assertSame(EntryClass::DAYBREAK, $first->getClass());
        self::assertSame(EntryClass::PAUSE, $gap->getClass());
        self::assertSame(EntryClass::OVERLAP, $overlap->getClass());
        self::assertSame(EntryClass::PLAIN, $seamless->getClass());
    }

    public function testUserIdZeroIsNoOp(): void
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::never())->method('getManager');

        new DayClassService($managerRegistry)->recalculate(0, '2026-06-15');
    }
}
```

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Tracking/DayClassServiceTest.php` — Expected: FAIL, class not found.

- [ ] **Step 2: Create the service (moved body, unchanged semantics)**

`src/Service/Tracking/DayClassService.php`:

```php
namespace App\Service\Tracking;

use App\Entity\Entry;
use App\Enum\EntryClass;
use App\Repository\EntryRepository;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;

use function assert;
use function count;

/**
 * Sets rendering classes for pause, overlap and daybreak (extracted from BaseTrackingController).
 */
class DayClassService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    /**
     * v4 semantics: the class describes the transition BEFORE an entry. The first entry of a
     * day always marks the day break; every further entry is a pause (gap to the previous
     * entry), an overlap (starts before the previous one ended) or plain (seamless continuation).
     *
     * @throws Exception when database operations fail
     */
    public function recalculate(int $userId, string $day): void
    {
        if (0 === $userId) {
            return;
        }

        $objectManager = $this->managerRegistry->getManager();
        $objectRepository = $objectManager->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
        $entries = $objectRepository->findByDay($userId, $day);

        if (0 === count($entries)) {
            return;
        }

        $firstEntry = $entries[0];
        if (EntryClass::DAYBREAK !== $firstEntry->getClass()) {
            $firstEntry->setClass(EntryClass::DAYBREAK);
            $objectManager->persist($firstEntry);
            $objectManager->flush();
        }

        $counter = count($entries);
        for ($i = 1; $i < $counter; ++$i) {
            $entry = $entries[$i];
            $previous = $entries[$i - 1];

            $start = $entry->getStart();
            $previousEnd = $previous->getEnd();
            if (!$start instanceof DateTime) {
                continue;
            }
            if (!$previousEnd instanceof DateTime) {
                continue;
            }

            $entryClass = EntryClass::PLAIN;
            if ($start->format('H:i') > $previousEnd->format('H:i')) {
                $entryClass = EntryClass::PAUSE;
            } elseif ($start->format('H:i') < $previousEnd->format('H:i')) {
                $entryClass = EntryClass::OVERLAP;
            }

            if ($entryClass !== $entry->getClass()) {
                $entry->setClass($entryClass);
                $objectManager->persist($entry);
                $objectManager->flush();
            }
        }
    }
}
```

- [ ] **Step 3: Delegate in the controller**

In `src/Controller/Tracking/BaseTrackingController.php`, replace the whole `calculateClasses` body with a delegation (keep the method signature and docblock so all subclass call sites stay untouched). Check how the controller receives dependencies (constructor or `#[Required]` setters) and inject `DayClassService` the same way; then:

```php
protected function calculateClasses(int $userId, string $day): void
{
    $this->dayClassService->recalculate($userId, $day);
}
```

Remove imports that became unused in the controller (only those your change orphaned — `EntryClass`, `DateTime` etc. IF nothing else in the file uses them; verify with grep before removing).

- [ ] **Step 4: Run the new test + the tracking controller tests**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Tracking/DayClassServiceTest.php
docker compose --profile dev exec app-dev composer test:controller
```
Expected: both green — the controller suite is the behavioral safety net for the move.

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add src/Service/Tracking/DayClassService.php src/Controller/Tracking/BaseTrackingController.php tests/Service/Tracking/DayClassServiceTest.php
git commit -S --signoff -m "refactor(tracking): extract day-class recalculation into DayClassService"
```

---

### Task 4: TicketProjectResolver

**Files:**
- Create: `src/Service/Sync/TicketProjectResolver.php`, `src/ValueObject/Sync/ProjectResolution.php`
- Test: `tests/Service/Sync/TicketProjectResolverTest.php`

**Interfaces:**
- Consumes: `ProjectRepository::findByTicketSystem` (Task 2), `Project::getJiraId(): ?string` (comma-separated prefixes), `Project::getSubtickets()` (comma-separated keys), ADR-020 precedence: exact subticket match wins over prefix match.
- Produces: `TicketProjectResolver::resolve(string $issueKey, TicketSystem $ticketSystem): ProjectResolution`; `ProjectResolution` readonly `(?Project $project, string $reason)` — `project === null` means parked (`reason` says why: no match / ambiguous). Per-ticket-system project list cached in the service instance for the process lifetime of a run.

- [ ] **Step 1: Write the failing test**

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Repository\ProjectRepository;
use App\Service\Sync\TicketProjectResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TicketProjectResolver::class)]
#[AllowMockObjectsWithoutExpectations]
final class TicketProjectResolverTest extends TestCase
{
    private function project(int $id, ?string $jiraId, string $subtickets = ''): Project
    {
        $project = self::createStub(Project::class);
        $project->method('getId')->willReturn($id);
        $project->method('getJiraId')->willReturn($jiraId);
        $project->method('getSubtickets')->willReturn($subtickets);

        return $project;
    }

    /**
     * @param list<Project> $projects
     */
    private function resolver(array $projects): TicketProjectResolver
    {
        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('findByTicketSystem')->willReturn($projects);

        return new TicketProjectResolver($projectRepository);
    }

    private function ticketSystem(): TicketSystem
    {
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getId')->willReturn(1);

        return $ticketSystem;
    }

    public function testPrefixMatchResolvesProject(): void
    {
        $resolution = $this->resolver([$this->project(1, 'SA'), $this->project(2, 'TIM, OPS')])
            ->resolve('TIM-42', $this->ticketSystem());

        self::assertSame(2, $resolution->project?->getId());
    }

    public function testExactSubticketWinsOverPrefix(): void
    {
        $byPrefix = $this->project(1, 'TIM');
        $bySubticket = $this->project(2, 'SA', 'TIM-42, TIM-43');

        $resolution = $this->resolver([$byPrefix, $bySubticket])->resolve('TIM-42', $this->ticketSystem());

        self::assertSame(2, $resolution->project?->getId());
    }

    public function testNoMatchParks(): void
    {
        $resolution = $this->resolver([$this->project(1, 'SA')])->resolve('TIM-42', $this->ticketSystem());

        self::assertNull($resolution->project);
        self::assertStringContainsString('no project', $resolution->reason);
    }

    public function testAmbiguousPrefixParks(): void
    {
        $resolution = $this->resolver([$this->project(1, 'TIM'), $this->project(2, 'TIM')])
            ->resolve('TIM-42', $this->ticketSystem());

        self::assertNull($resolution->project);
        self::assertStringContainsString('ambiguous', $resolution->reason);
    }

    public function testSubticketMatchIsCaseInsensitive(): void
    {
        $resolution = $this->resolver([$this->project(1, 'SA', 'tim-42')])->resolve('TIM-42', $this->ticketSystem());

        self::assertSame(1, $resolution->project?->getId());
    }
}
```

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/TicketProjectResolverTest.php` — Expected: FAIL.

- [ ] **Step 2: Implement**

`src/ValueObject/Sync/ProjectResolution.php`:

```php
namespace App\ValueObject\Sync;

use App\Entity\Project;

final readonly class ProjectResolution
{
    public function __construct(
        public ?Project $project,
        public string $reason = '',
    ) {
    }
}
```

`src/Service/Sync/TicketProjectResolver.php`:

```php
namespace App\Service\Sync;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Repository\ProjectRepository;
use App\ValueObject\Sync\ProjectResolution;

use function array_map;
use function count;
use function explode;
use function in_array;
use function sprintf;
use function strcasecmp;
use function strstr;
use function trim;

/**
 * Maps a Jira issue key to the owning TT project (ADR-023 import; ADR-020 precedence:
 * exact subticket match wins over jira_id prefix match). Ambiguity parks the worklog.
 */
class TicketProjectResolver
{
    /** @var array<int, list<Project>> */
    private array $projectsByTicketSystem = [];

    public function __construct(private readonly ProjectRepository $projectRepository)
    {
    }

    public function resolve(string $issueKey, TicketSystem $ticketSystem): ProjectResolution
    {
        $projects = $this->projectsFor($ticketSystem);

        $subticketOwners = [];
        foreach ($projects as $project) {
            $subtickets = array_map(trim(...), explode(',', $project->getSubtickets() ?? ''));
            foreach ($subtickets as $subticket) {
                if ('' !== $subticket && 0 === strcasecmp($subticket, $issueKey)) {
                    $subticketOwners[] = $project;
                    break;
                }
            }
        }

        if (1 === count($subticketOwners)) {
            return new ProjectResolution($subticketOwners[0], 'exact subticket match');
        }

        if (count($subticketOwners) > 1) {
            return new ProjectResolution(null, sprintf('ambiguous: %d projects list %s as subticket', count($subticketOwners), $issueKey));
        }

        $prefix = strstr($issueKey, '-', true);
        if (false === $prefix || '' === $prefix) {
            return new ProjectResolution(null, sprintf('no project resolvable: %s has no key prefix', $issueKey));
        }

        $prefixOwners = [];
        foreach ($projects as $project) {
            $jiraId = $project->getJiraId();
            if (null === $jiraId || '' === $jiraId) {
                continue;
            }

            $allowedPrefixes = array_map(trim(...), explode(',', $jiraId));
            if (in_array($prefix, $allowedPrefixes, true)) {
                $prefixOwners[] = $project;
            }
        }

        if (1 === count($prefixOwners)) {
            return new ProjectResolution($prefixOwners[0], 'jira_id prefix match');
        }

        if (count($prefixOwners) > 1) {
            return new ProjectResolution(null, sprintf('ambiguous: %d projects claim prefix %s', count($prefixOwners), $prefix));
        }

        return new ProjectResolution(null, sprintf('no project for prefix %s on this ticket system', $prefix));
    }

    /**
     * @return list<Project>
     */
    private function projectsFor(TicketSystem $ticketSystem): array
    {
        $key = (int) $ticketSystem->getId();

        return $this->projectsByTicketSystem[$key] ??= $this->projectRepository->findByTicketSystem($ticketSystem);
    }
}
```

Note the prefix comparison is case-SENSITIVE (`in_array(..., true)`) exactly like `SaveEntryAction::validateTicketPrefix`; subticket match is case-insensitive like `isKnownSubticket`.

- [ ] **Step 3: Run test to verify it passes** — Expected: PASS (5 tests).

- [ ] **Step 4: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Sync/TicketProjectResolver.php src/ValueObject/Sync/ProjectResolution.php tests/Service/Sync/TicketProjectResolverTest.php
git commit -S --signoff -m "feat(sync): ticket-to-project resolver with ADR-020 precedence (ADR-023)"
```

---

### Task 5: JiraAuthorMapper

**Files:**
- Create: `src/Service/Sync/JiraAuthorMapper.php`
- Test: `tests/Service/Sync/JiraAuthorMapperTest.php`

**Interfaces:**
- Consumes: `JiraWorkLog` author fields (`authorAccountId`, `authorName`, `authorEmail`), `UserTicketsystem::setRemoteAccountId` (Task 1), `User` (`setUsername`, `setActive(false)`), EM repositories.
- Produces:
  - `find(JiraWorkLog $workLog, TicketSystem $ticketSystem): ?User` — pure lookup, but persists (no flush) a `remote_account_id` mapping when auto-match succeeds so it becomes durable.
  - `createShadow(JiraWorkLog $workLog, TicketSystem $ticketSystem): User` — persists (no flush) a shadow `User` (`active=false`) + a `UserTicketsystem` row (`accessToken`/`tokenSecret` set to `''` — NOT NULL text columns, no tokens) carrying `remote_account_id`.
  - `remoteKey(JiraWorkLog $workLog): ?string` — `authorAccountId ?? authorName ?? authorEmail`, the per-run cache key for the import service.

Matching order in `find()`:
1. `UserTicketsystem` by `(ticketSystem, remoteAccountId = accountId)`, then `(ticketSystem, remoteAccountId = authorName)` — the durable mapping.
2. TT `username` equals `authorName` (case-insensitive DB collation via `findOneBy(['username' => ...])`).
3. TT `username` equals the localpart of `authorEmail` (before `@`).
On a rule-2/3 hit: update the user's existing `UserTicketsystem` row for this ticket system with the remote id, or persist a new tokenless row.

Shadow username: `authorName ?? email-localpart ?? 'jira-' . substr(accountId, 0, 43)`, truncated to 50 chars; on collision with an existing username append `-2`, `-3`, … (collision means rule 2/3 didn't match, e.g. the name maps to a different account already).

- [ ] **Step 1: Write the failing test**

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Service\Sync\JiraAuthorMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(JiraAuthorMapper::class)]
#[AllowMockObjectsWithoutExpectations]
final class JiraAuthorMapperTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    /** @var EntityRepository&MockObject */
    private EntityRepository&MockObject $userTicketsystemRepository;
    /** @var EntityRepository&MockObject */
    private EntityRepository&MockObject $userRepository;
    private JiraAuthorMapper $mapper;
    private TicketSystem $ticketSystem;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userTicketsystemRepository = $this->createMock(EntityRepository::class);
        $this->userRepository = $this->createMock(EntityRepository::class);
        $this->entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository&MockObject => UserTicketsystem::class === $class ? $this->userTicketsystemRepository : $this->userRepository,
        );
        $this->mapper = new JiraAuthorMapper($this->entityManager);
        $this->ticketSystem = self::createStub(TicketSystem::class);
    }

    public function testFindByStoredRemoteAccountId(): void
    {
        $user = new User();
        $mapping = (new UserTicketsystem())->setUser($user);
        $this->userTicketsystemRepository->method('findOneBy')->willReturn($mapping);

        $found = $this->mapper->find(new JiraWorkLog(id: 1, authorAccountId: 'abc-123'), $this->ticketSystem);

        self::assertSame($user, $found);
    }

    public function testFindByUsernameMatchPersistsMapping(): void
    {
        $user = new User()->setUsername('jdoe');
        $this->userTicketsystemRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => ($criteria['username'] ?? null) === 'jdoe' ? $user : null,
        );
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $object) use (&$persisted): void { $persisted[] = $object; },
        );

        $found = $this->mapper->find(new JiraWorkLog(id: 1, authorAccountId: 'abc-123', authorName: 'jdoe'), $this->ticketSystem);

        self::assertSame($user, $found);
        self::assertCount(1, $persisted);
        self::assertInstanceOf(UserTicketsystem::class, $persisted[0]);
        self::assertSame('abc-123', $persisted[0]->getRemoteAccountId());
    }

    public function testFindByEmailLocalpart(): void
    {
        $user = new User()->setUsername('jdoe');
        $this->userTicketsystemRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => ($criteria['username'] ?? null) === 'jdoe' ? $user : null,
        );

        $found = $this->mapper->find(new JiraWorkLog(id: 1, authorEmail: 'jdoe@example.com'), $this->ticketSystem);

        self::assertSame($user, $found);
    }

    public function testFindReturnsNullForUnknownAuthor(): void
    {
        $this->userTicketsystemRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findOneBy')->willReturn(null);

        self::assertNull($this->mapper->find(new JiraWorkLog(id: 1, authorName: 'ghost'), $this->ticketSystem));
    }

    public function testCreateShadowPersistsInactiveUserWithMapping(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(null);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $object) use (&$persisted): void { $persisted[] = $object; },
        );

        $shadow = $this->mapper->createShadow(new JiraWorkLog(id: 1, authorAccountId: 'abc-123', authorName: 'ghost'), $this->ticketSystem);

        self::assertSame('ghost', $shadow->getUsername());
        self::assertFalse($shadow->getActive());
        self::assertContains($shadow, $persisted);
        $mappings = array_filter($persisted, static fn (object $object): bool => $object instanceof UserTicketsystem);
        self::assertCount(1, $mappings);
    }

    public function testShadowUsernameCollisionGetsSuffix(): void
    {
        $this->userRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => ($criteria['username'] ?? null) === 'ghost' ? new User() : null,
        );
        $this->entityManager->method('persist');

        $shadow = $this->mapper->createShadow(new JiraWorkLog(id: 1, authorName: 'ghost'), $this->ticketSystem);

        self::assertSame('ghost-2', $shadow->getUsername());
    }

    public function testRemoteKeyPrefersAccountId(): void
    {
        self::assertSame('abc', $this->mapper->remoteKey(new JiraWorkLog(id: 1, authorAccountId: 'abc', authorName: 'n')));
        self::assertSame('n', $this->mapper->remoteKey(new JiraWorkLog(id: 1, authorName: 'n')));
        self::assertNull($this->mapper->remoteKey(new JiraWorkLog(id: 1)));
    }
}
```

Run — Expected: FAIL, class not found.

- [ ] **Step 2: Implement**

`src/Service/Sync/JiraAuthorMapper.php`:

```php
namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;
use function strstr;
use function substr;

/**
 * Maps Jira worklog authors to TT users (ADR-023 §3): durable remote_account_id mapping,
 * auto-match by username (Jira name / email localpart), shadow-user creation for unknowns.
 */
class JiraAuthorMapper
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function remoteKey(JiraWorkLog $jiraWorkLog): ?string
    {
        return $jiraWorkLog->authorAccountId ?? $jiraWorkLog->authorName ?? $jiraWorkLog->authorEmail;
    }

    public function find(JiraWorkLog $jiraWorkLog, TicketSystem $ticketSystem): ?User
    {
        foreach ([$jiraWorkLog->authorAccountId, $jiraWorkLog->authorName] as $remoteId) {
            if (null === $remoteId || '' === $remoteId) {
                continue;
            }

            $mapping = $this->entityManager->getRepository(UserTicketsystem::class)
                ->findOneBy(['ticketSystem' => $ticketSystem, 'remoteAccountId' => $remoteId]);
            if ($mapping instanceof UserTicketsystem && $mapping->getUser() instanceof User) {
                return $mapping->getUser();
            }
        }

        foreach ([$jiraWorkLog->authorName, $this->emailLocalpart($jiraWorkLog->authorEmail)] as $candidate) {
            if (null === $candidate || '' === $candidate) {
                continue;
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $candidate]);
            if ($user instanceof User) {
                $this->persistMapping($user, $jiraWorkLog, $ticketSystem);

                return $user;
            }
        }

        return null;
    }

    public function createShadow(JiraWorkLog $jiraWorkLog, TicketSystem $ticketSystem): User
    {
        $base = $jiraWorkLog->authorName
            ?? $this->emailLocalpart($jiraWorkLog->authorEmail)
            ?? 'jira-' . substr((string) $jiraWorkLog->authorAccountId, 0, 43);
        $base = substr($base, 0, 50);

        $username = $base;
        $suffix = 2;
        while ($this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]) instanceof User) {
            $username = substr($base, 0, 50 - strlen((string) $suffix) - 1) . '-' . $suffix;
            ++$suffix;
        }

        $shadow = new User();
        $shadow->setUsername($username);
        $shadow->setActive(false);
        $this->entityManager->persist($shadow);

        $this->persistMapping($shadow, $jiraWorkLog, $ticketSystem, alwaysCreate: true);

        return $shadow;
    }

    private function persistMapping(User $user, JiraWorkLog $jiraWorkLog, TicketSystem $ticketSystem, bool $alwaysCreate = false): void
    {
        $remoteId = $this->remoteKey($jiraWorkLog);
        if (null === $remoteId) {
            return;
        }

        if (!$alwaysCreate) {
            $existing = $this->entityManager->getRepository(UserTicketsystem::class)
                ->findOneBy(['ticketSystem' => $ticketSystem, 'user' => $user]);
            if ($existing instanceof UserTicketsystem) {
                $existing->setRemoteAccountId($remoteId);

                return;
            }
        }

        $mapping = new UserTicketsystem();
        $mapping->setUser($user);
        $mapping->setTicketSystem($ticketSystem);
        $mapping->setAccessToken('');
        $mapping->setTokenSecret('');
        $mapping->setRemoteAccountId($remoteId);
        $this->entityManager->persist($mapping);
    }

    private function emailLocalpart(?string $email): ?string
    {
        if (null === $email) {
            return null;
        }

        $localpart = strstr($email, '@', true);

        return false === $localpart || '' === $localpart ? null : $localpart;
    }
}
```

Check `UserTicketsystem::setUser/setTicketSystem/setAccessToken/setTokenSecret` exist with these names (they do — fluent setters); `strlen` needs a `use function strlen;`.

Note for the test `testFindByUsernameMatchPersistsMapping`: `find()` first checks for an existing `(ticketSystem, user)` row via the mocked repository which returns null for every `findOneBy` → falls through to creating the mapping row. That's the asserted persist.

- [ ] **Step 3: Run test to verify it passes** — Expected: PASS (7 tests).

- [ ] **Step 4: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Sync/JiraAuthorMapper.php tests/Service/Sync/JiraAuthorMapperTest.php
git commit -S --signoff -m "feat(sync): jira author mapper with auto-match and shadow users (ADR-023 §3)"
```

---

### Task 6: ImportWorklogsService

**Files:**
- Create: `src/Service/Sync/ImportWorklogsService.php`
- Test: `tests/Service/Sync/ImportWorklogsServiceTest.php`

**Interfaces:**
- Consumes: everything above plus Phase 1 (`JiraOAuthApiFactory`, read methods, `RemoteWorklogNormalizer`, `WorklogSyncState`, `SyncRun`/`SyncRunItem`, `SyncRunType::IMPORT`).
- Produces:
  ```php
  public function import(
      User $triggeredBy,
      TicketSystem $ticketSystem,
      DateTimeImmutable $from,
      DateTimeImmutable $to,
      int $defaultActivityId,
      array $targetUsernames = [],   // list<string>; empty = import for ALL mapped/creatable authors
      bool $dryRun = false,
  ): SyncRun
  ```
- Behavior contract (each line is a test):
  1. Creates `SyncRun` type `IMPORT`, scope `{from, to, dry_run, default_activity_id, users}`; unknown activity id → run FAILED with ERROR item, nothing else done.
  2. JQL is `worklogDate >= "…" AND worklogDate <= "…"` under the **triggering user's token** (their Jira visibility is the authorization boundary); search truncation → TRUNCATED item.
  3. Worklog already linked (`findOneByWorklogIdAndTicketSystem`) → counter `already_linked`, skip.
  4. Author unknown + `targetUsernames` set → counter `skipped_author`, skip (no shadow for filtered-out authors). Author known but not in `targetUsernames` → same counter.
  5. Author unknown + no filter + real run → shadow user created (counter `shadow_users_created` + SHADOW_USER_CREATED item). Dry run → item SHADOW_USER_CREATED with reason prefixed `dry-run:` but NO persist.
  6. Unresolvable/ambiguous project → one UNRESOLVED_PROJECT item per issue key + counter `unresolved_project` per worklog, skip.
  7. Unlinked duplicate (`findUnlinkedDuplicate`) → PROBABLE_DUPLICATE item (payload: remote snapshot + candidate entry id), counter `probable_duplicate`, skip.
  8. Worklog whose start-time + duration crosses midnight → ERROR item `worklog crosses midnight`, counter `errors`, skip.
  9. Otherwise real run: creates the Entry — user, project, customer from `$project->getCustomer()`, default activity, `ticket = issueKey`, day/start from the snapshot timestamp (server TZ), `end = start + duration`, explicit `setDuration`, `EntryClass::PLAIN`, **`worklogId` set, `syncedToTicketsystem = true`** — plus a `WorklogSyncState` row (status IN_SYNC, `basePayload = snapshot->toArray()`, `baseUpdatedAt = worklog->updated ?? ''`), counter `created`. NO `EntryEvent`. Dry run → counter `would_create`, nothing persisted, no item (counters are the preview; parked items appear identically).
  10. After processing: flush, then `DayClassService::recalculate` once per affected (user, day).
  11. Per-issue fetch failure → ERROR item, run continues (same resilience as verify).

- [ ] **Step 1: Write the failing test**

`tests/Service/Sync/ImportWorklogsServiceTest.php` — same mock kit as `VerifyWorklogsServiceTest` (copy its setUp idiom), plus mocks for `ProjectRepository` (via resolver), `JiraAuthorMapper`-collaborating repositories, `DayClassService`. Test the contract lines with these cases (full code):

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\DTO\Jira\JiraIssueKeySearchResult;
use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Repository\EntryRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\ImportWorklogsService;
use App\Service\Sync\JiraAuthorMapper;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\TicketProjectResolver;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\ProjectResolution;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ImportWorklogsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class ImportWorklogsServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private EntryRepository&MockObject $entryRepository;
    private JiraOAuthApiService&MockObject $api;
    private TicketProjectResolver&MockObject $projectResolver;
    private JiraAuthorMapper&MockObject $authorMapper;
    private DayClassService&MockObject $dayClassService;
    private ImportWorklogsService $service;
    private User $triggeredBy;
    private User $author;
    private TicketSystem $ticketSystem;
    private Project $project;
    private Activity $activity;
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->projectResolver = $this->createMock(TicketProjectResolver::class);
        $this->authorMapper = $this->createMock(JiraAuthorMapper::class);
        $this->dayClassService = $this->createMock(DayClassService::class);

        $apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $apiFactory->method('create')->willReturn($this->api);
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'po'));

        $this->activity = self::createStub(Activity::class);
        $this->activity->method('getId')->willReturn(1);
        $this->entityManager->method('find')->willReturnCallback(
            fn (string $class, mixed $id): ?object => Activity::class === $class && 1 === $id ? $this->activity : null,
        );
        $this->entityManager->method('persist')->willReturnCallback(
            function (object $object): void { $this->persisted[] = $object; },
        );

        $customer = self::createStub(Customer::class);
        $this->project = self::createStub(Project::class);
        $this->project->method('getCustomer')->willReturn($customer);
        $this->triggeredBy = self::createStub(User::class);
        $this->author = self::createStub(User::class);
        $this->author->method('getId')->willReturn(7);
        $this->author->method('getUsername')->willReturn('jdoe');
        $this->ticketSystem = self::createStub(TicketSystem::class);

        $this->service = new ImportWorklogsService(
            $this->entityManager,
            $this->entryRepository,
            $apiFactory,
            new RemoteWorklogNormalizer(),
            $this->projectResolver,
            $this->authorMapper,
            $this->dayClassService,
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    private function stubRemote(array $issueKeys, array $worklogsByIssue): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult($issueKeys, false));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static fn (string $key): array => $worklogsByIssue[$key] ?? [],
        );
    }

    private function worklog(int $id = 5001): JiraWorkLog
    {
        return new JiraWorkLog(
            id: $id,
            comment: 'jira-side work',
            started: '2026-06-10T09:00:00.000+0200',
            timeSpentSeconds: 3600,
            updated: '2026-06-10T10:00:00.000+0200',
            authorAccountId: 'acc-jdoe',
            authorName: 'jdoe',
        );
    }

    private function import(array $targetUsernames = [], bool $dryRun = false): SyncRun
    {
        return $this->service->import($this->triggeredBy, $this->ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'), 1, $targetUsernames, $dryRun);
    }

    public function testUnknownActivityFailsRun(): void
    {
        $syncRun = $this->service->import($this->triggeredBy, $this->ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'), 99);

        self::assertSame(SyncRunStatus::FAILED, $syncRun->getStatus());
    }

    public function testImportsWorklogAsPreSyncedEntryWithSyncState(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);

        $syncRun = $this->import();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);
        $entries = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('TIM-1', $entry->getTicket());
        self::assertSame(5001, $entry->getWorklogId());
        self::assertTrue($entry->getSyncedToTicketsystem());
        self::assertSame(60, $entry->getDuration());
        $states = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
        self::assertCount(1, $states);
        self::assertSame('2026-06-10T10:00:00.000+0200', $states[0]->getBaseUpdatedAt());
    }

    public function testAlreadyLinkedWorklogIsSkipped(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(new Entry());

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['already_linked'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testUnknownAuthorWithTargetFilterSkips(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn(null);

        $syncRun = $this->import(targetUsernames: ['someoneelse']);

        self::assertSame(1, $syncRun->getCounters()['skipped_author'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
    }

    public function testUnknownAuthorWithoutFilterCreatesShadow(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn(null);
        $this->authorMapper->method('createShadow')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['shadow_users_created'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);
        $kinds = array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray());
        self::assertContains(SyncItemKind::SHADOW_USER_CREATED, $kinds);
    }

    public function testUnresolvedProjectParks(): void
    {
        $this->stubRemote(['XXX-1'], ['XXX-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution(null, 'no project for prefix XXX on this ticket system'));

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['unresolved_project'] ?? 0);
        $items = $syncRun->getItems()->toArray();
        self::assertCount(1, $items);
        self::assertSame(SyncItemKind::UNRESOLVED_PROJECT, $items[0]->getKind());
    }

    public function testProbableDuplicateParks(): void
    {
        $duplicate = self::createStub(Entry::class);
        $duplicate->method('getId')->willReturn(77);
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn($duplicate);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['probable_duplicate'] ?? 0);
        self::assertSame(SyncItemKind::PROBABLE_DUPLICATE, $syncRun->getItems()->toArray()[0]->getKind());
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testMidnightCrossingWorklogParksAsError(): void
    {
        $late = new JiraWorkLog(id: 5002, comment: 'late', started: '2026-06-10T23:30:00.000+0200', timeSpentSeconds: 7200, authorAccountId: 'acc-jdoe');
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$late]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(0, $syncRun->getCounters()['created'] ?? 0);
    }

    public function testDryRunCountsWithoutPersisting(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog()]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import(dryRun: true);

        self::assertSame(1, $syncRun->getCounters()['would_create'] ?? 0);
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof Entry));
        self::assertSame([], array_filter($this->persisted, static fn (object $o): bool => $o instanceof WorklogSyncState));
    }

    public function testDayClassesRecalculatedPerAffectedUserDay(): void
    {
        $this->stubRemote(['TIM-1'], ['TIM-1' => [$this->worklog(5001), $this->worklog(5003)]]);
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $this->dayClassService->expects(self::once())->method('recalculate')->with(7, '2026-06-10');

        $this->import();
    }

    public function testIssueFetchFailureContinues(): void
    {
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult(['BAD-1', 'TIM-1'], false));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            fn (string $key): array => 'BAD-1' === $key ? throw new \RuntimeException('gone') : [$this->worklog()],
        );
        $this->entryRepository->method('findOneByWorklogIdAndTicketSystem')->willReturn(null);
        $this->entryRepository->method('findUnlinkedDuplicate')->willReturn(null);
        $this->projectResolver->method('resolve')->willReturn(new ProjectResolution($this->project, 'prefix'));
        $this->authorMapper->method('remoteKey')->willReturn('acc-jdoe');
        $this->authorMapper->method('find')->willReturn($this->author);

        $syncRun = $this->import();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(1, $syncRun->getCounters()['created'] ?? 0);
    }
}
```

Run — Expected: FAIL, `ImportWorklogsService` not found.

- [ ] **Step 2: Implement**

`src/Service/Sync/ImportWorklogsService.php`:

```php
namespace App\Service\Sync;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\WorklogSyncState;
use App\Enum\EntryClass;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogSyncStatus;
use App\Repository\EntryRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Tracking\DayClassService;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;

use function in_array;
use function sprintf;
use function substr;

/**
 * ADR-023 Phase 2: imports Jira worklogs as pre-synced TT entries. Never dispatches
 * EntryEvent — imported entries must not echo back to Jira as new worklogs.
 */
class ImportWorklogsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly TicketProjectResolver $ticketProjectResolver,
        private readonly JiraAuthorMapper $jiraAuthorMapper,
        private readonly DayClassService $dayClassService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $targetUsernames empty = import for all mapped/creatable authors
     */
    public function import(
        User $triggeredBy,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $defaultActivityId,
        array $targetUsernames = [],
        bool $dryRun = false,
    ): SyncRun {
        $syncRun = new SyncRun()
            ->setType(SyncRunType::IMPORT)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($triggeredBy)
            ->setScope([
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'dry_run' => $dryRun,
                'default_activity_id' => $defaultActivityId,
                'users' => $targetUsernames,
            ])
            ->setCounters([])
            ->setStartedAt(DateTimeImmutable::createFromInterface($this->clock->now()));

        $this->entityManager->persist($syncRun);

        try {
            $this->run($syncRun, $triggeredBy, $ticketSystem, $from, $to, $defaultActivityId, $targetUsernames, $dryRun);
            $syncRun->setStatus(SyncRunStatus::COMPLETED);
        } catch (Throwable $throwable) {
            $syncRun->setStatus(SyncRunStatus::FAILED);
            $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($throwable->getMessage(), 0, 255));
        }

        $syncRun->setFinishedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        return $syncRun;
    }

    /**
     * @param list<string> $targetUsernames
     */
    private function run(
        SyncRun $syncRun,
        User $triggeredBy,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $defaultActivityId,
        array $targetUsernames,
        bool $dryRun,
    ): void {
        $activity = $this->entityManager->find(Activity::class, $defaultActivityId);
        if (!$activity instanceof Activity) {
            throw new InvalidArgumentException('Unknown default activity id: ' . $defaultActivityId);
        }

        $api = $this->jiraOAuthApiFactory->create($triggeredBy, $ticketSystem);

        $jql = sprintf('worklogDate >= "%s" AND worklogDate <= "%s"', $from->format('Y-m-d'), $to->format('Y-m-d'));
        $searchResult = $api->searchIssueKeysWithWorklogs($jql);
        if ($searchResult->truncated) {
            $this->addItem($syncRun, SyncItemKind::TRUNCATED, reason: 'issue search hit its result cap; import may be incomplete — narrow the date range and re-run');
        }

        $rangeFrom = $from->setTime(0, 0)->getTimestamp();
        $rangeTo = $to->setTime(23, 59, 59)->getTimestamp();

        /** @var array<string, ?User> $authorCache */
        $authorCache = [];
        /** @var array<string, true> $shadowAnnounced */
        $shadowAnnounced = [];
        /** @var array<string, array{userId: int, day: string}> $affectedDays */
        $affectedDays = [];
        $createdSinceFlush = 0;

        foreach ($searchResult->keys as $issueKey) {
            try {
                $issueWorklogs = $api->getIssueWorklogs($issueKey);
            } catch (Throwable $throwable) {
                $syncRun->incrementCounter('errors');
                $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, reason: substr('worklog fetch failed: ' . $throwable->getMessage(), 0, 255));
                continue;
            }

            $unresolvedAnnounced = false;

            foreach ($issueWorklogs as $jiraWorkLog) {
                if (null === $jiraWorkLog->id) {
                    continue;
                }

                try {
                    $snapshot = $this->remoteWorklogNormalizer->normalize($jiraWorkLog, $issueKey);
                } catch (InvalidArgumentException $exception) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: substr($exception->getMessage(), 0, 255));
                    continue;
                }

                if ($snapshot->startedTimestamp < $rangeFrom || $snapshot->startedTimestamp > $rangeTo) {
                    continue;
                }

                if ($this->entryRepository->findOneByWorklogIdAndTicketSystem($jiraWorkLog->id, $ticketSystem) instanceof Entry) {
                    $syncRun->incrementCounter('already_linked');
                    continue;
                }

                $remoteKey = $this->jiraAuthorMapper->remoteKey($jiraWorkLog);
                if (null === $remoteKey) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: 'worklog has no author identity');
                    continue;
                }

                if (!array_key_exists($remoteKey, $authorCache)) {
                    $authorCache[$remoteKey] = $this->jiraAuthorMapper->find($jiraWorkLog, $ticketSystem);
                }

                $user = $authorCache[$remoteKey];

                if ([] !== $targetUsernames && (!$user instanceof User || !in_array($user->getUsername(), $targetUsernames, true))) {
                    $syncRun->incrementCounter('skipped_author');
                    continue;
                }

                if (!$user instanceof User) {
                    if (!isset($shadowAnnounced[$remoteKey])) {
                        $shadowAnnounced[$remoteKey] = true;
                        $this->addItem(
                            $syncRun,
                            SyncItemKind::SHADOW_USER_CREATED,
                            issueKey: $issueKey,
                            author: $remoteKey,
                            reason: ($dryRun ? 'dry-run: would create shadow user for ' : 'created shadow user for ') . $remoteKey,
                        );
                        $syncRun->incrementCounter('shadow_users_created');
                    }

                    if ($dryRun) {
                        $syncRun->incrementCounter('would_create');
                        continue;
                    }

                    $user = $this->jiraAuthorMapper->createShadow($jiraWorkLog, $ticketSystem);
                    $authorCache[$remoteKey] = $user;
                }

                $resolution = $this->ticketProjectResolver->resolve($issueKey, $ticketSystem);
                $project = $resolution->project;
                if (!$project instanceof Project) {
                    $syncRun->incrementCounter('unresolved_project');
                    if (!$unresolvedAnnounced) {
                        $unresolvedAnnounced = true;
                        $this->addItem($syncRun, SyncItemKind::UNRESOLVED_PROJECT, issueKey: $issueKey, reason: substr($resolution->reason, 0, 255));
                    }

                    continue;
                }

                $day = new DateTime()->setTimestamp($snapshot->startedTimestamp);
                $start = clone $day;
                $end = (clone $start)->modify(sprintf('+%d minutes', $snapshot->durationMinutes));
                if ($end->format('Y-m-d') !== $day->format('Y-m-d')) {
                    $syncRun->incrementCounter('errors');
                    $this->addItem($syncRun, SyncItemKind::ERROR, issueKey: $issueKey, remoteWorklogId: $jiraWorkLog->id, reason: 'worklog crosses midnight; import manually');
                    continue;
                }

                $duplicate = $this->entryRepository->findUnlinkedDuplicate($user, $issueKey, $day, $snapshot->durationMinutes);
                if ($duplicate instanceof Entry) {
                    $syncRun->incrementCounter('probable_duplicate');
                    $this->addItem(
                        $syncRun,
                        SyncItemKind::PROBABLE_DUPLICATE,
                        issueKey: $issueKey,
                        remoteWorklogId: $jiraWorkLog->id,
                        entry: $duplicate,
                        author: $remoteKey,
                        reason: sprintf('unlinked entry %d matches user+ticket+day+duration', (int) $duplicate->getId()),
                        payload: ['remote' => $snapshot->toArray(), 'updated' => $jiraWorkLog->updated],
                    );
                    continue;
                }

                if ($dryRun) {
                    $syncRun->incrementCounter('would_create');
                    continue;
                }

                $this->createEntry($syncRun, $user, $project, $activity, $ticketSystem, $issueKey, $jiraWorkLog->id, $snapshot, $jiraWorkLog->updated, $day, $start, $end);
                $dayKey = $user->getId() . '|' . $day->format('Y-m-d');
                $affectedDays[$dayKey] = ['userId' => (int) $user->getId(), 'day' => $day->format('Y-m-d')];

                ++$createdSinceFlush;
                if ($createdSinceFlush >= 100) {
                    $this->entityManager->flush();
                    $createdSinceFlush = 0;
                }
            }
        }

        $this->entityManager->flush();

        foreach ($affectedDays as $affected) {
            $this->dayClassService->recalculate($affected['userId'], $affected['day']);
        }
    }

    private function createEntry(
        SyncRun $syncRun,
        User $user,
        Project $project,
        Activity $activity,
        TicketSystem $ticketSystem,
        string $issueKey,
        int $worklogId,
        WorklogSnapshot $snapshot,
        ?string $remoteUpdated,
        DateTime $day,
        DateTime $start,
        DateTime $end,
    ): void {
        $entry = new Entry();
        $entry->setUser($user)
            ->setProject($project)
            ->setActivity($activity)
            ->setTicket($issueKey)
            ->setDescription($snapshot->comment)
            ->setDay($day->format('Y-m-d'))
            ->setStart($start->format('H:i:s'))
            ->setEnd($end->format('H:i:s'))
            ->setClass(EntryClass::PLAIN)
            ->setWorklogId($worklogId);

        $customer = $project->getCustomer();
        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }

        $entry->setDuration($snapshot->durationMinutes);
        $entry->setSyncedToTicketsystem(true);

        $this->entityManager->persist($entry);

        $syncState = new WorklogSyncState()
            ->setEntry($entry)
            ->setTicketSystem($ticketSystem)
            ->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($snapshot->toArray())
            ->setBaseUpdatedAt($remoteUpdated ?? '')
            ->setLastSyncedAt(DateTimeImmutable::createFromInterface($this->clock->now()))
            ->setLastSyncRun($syncRun);
        $this->entityManager->persist($syncState);

        $syncRun->incrementCounter('created');
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function addItem(
        SyncRun $syncRun,
        SyncItemKind $kind,
        ?string $issueKey = null,
        ?int $remoteWorklogId = null,
        ?Entry $entry = null,
        ?string $author = null,
        string $reason = '',
        ?array $payload = null,
    ): void {
        $syncRun->addItem(
            new SyncRunItem()
                ->setKind($kind)
                ->setIssueKey($issueKey)
                ->setRemoteWorklogId($remoteWorklogId)
                ->setEntry($entry)
                ->setAuthor($author)
                ->setReason($reason)
                ->setPayload($payload)
                ->setCreatedAt(DateTimeImmutable::createFromInterface($this->clock->now())),
        );
    }
}
```

Note: `$authorCache` uses `array_key_exists` (not `isset`) because a cached `null` — author looked up and unknown — is a valid cache hit that must not re-query.

- [ ] **Step 3: Run test to verify it passes** — Expected: PASS (11 tests). Fix any assertion the draft misses by correcting the SERVICE (the tests are the contract), except where a test itself contradicts the interface block — then fix the test.

- [ ] **Step 4: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add src/Service/Sync/ImportWorklogsService.php tests/Service/Sync/ImportWorklogsServiceTest.php
git commit -S --signoff -m "feat(sync): import service creating pre-synced entries from Jira worklogs (ADR-023 use cases 1+3)"
```

---

### Task 7: `tt:import-worklogs` console command

**Files:**
- Create: `src/Command/TtImportWorklogsCommand.php`
- Test: `tests/Command/TtImportWorklogsCommandTest.php`

**Interfaces:**
- Consumes: `ImportWorklogsService::import(...)` (Task 6).
- Produces: `tt:import-worklogs <username> <ticket-system-id> [--from=Y-m-d] [--to=Y-m-d] [--user=NAME]* [--default-activity=ID (required)] [--dry-run]`; exit 0 on COMPLETED, 1 otherwise. Rendering mirrors `TtVerifyWorklogsCommand::render` (counters table + item lines).

- [ ] **Step 1: Write the failing test**

Model on `tests/Command/TtVerifyWorklogsCommandTest.php` (same mock kit: `ObjectRepository` mocks via `ManagerRegistry::getRepository` `willReturnMap`/callback, mocked `ImportWorklogsService`). Cases (full code, adapting the verify test's `commandTester()` helper):

```php
public function testRunsImportAndPrintsCounters(): void
// execute(['username' => 'po', 'ticket-system' => '1', '--from' => '2026-06-01', '--to' => '2026-06-30', '--default-activity' => '1'])
// mocked service returns a COMPLETED IMPORT run with counters ['created' => 5, 'probable_duplicate' => 1]
// assert exit 0, display contains 'created' and '5'

public function testMissingDefaultActivityFails(): void
// execute without --default-activity → exit 1, display contains 'default-activity'

public function testInvalidDateFails(): void
// '--from' => 'nope' → exit 1, display contains 'Invalid date'

public function testDryRunFlagIsPassedThrough(): void
// service mock expects import(..., dryRun: true) — use ->with() capturing the 7th argument === true
// execute with '--dry-run' => true

public function testUnknownUserFails(): void
// user repo returns null → exit 1, 'User not found'
```

Write these five tests fully in the file (copy the verify command test's structure — constructor `new TtImportWorklogsCommand($importService, $managerRegistry)`, `CommandTester`). For `testDryRunFlagIsPassedThrough` use:

```php
$importService->expects(self::once())->method('import')
    ->with(self::anything(), self::anything(), self::anything(), self::anything(), 1, [], true)
    ->willReturn($completedRun);
```

Run — Expected: FAIL, class not found.

- [ ] **Step 2: Implement the command**

`src/Command/TtImportWorklogsCommand.php` — same shape as `TtVerifyWorklogsCommand` (invokable, `#[AsCommand]`, `SymfonyStyle`):

```php
namespace App\Command;

use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Service\Sync\ImportWorklogsService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(name: 'tt:import-worklogs', description: 'Import Jira worklogs as pre-synced entries (ADR-023)')]
class TtImportWorklogsCommand extends Command
{
    public function __construct(
        private readonly ImportWorklogsService $importWorklogsService,
        private readonly ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    /**
     * @param list<string> $users
     */
    public function __invoke(
        #[Argument(description: 'TimeTracker username whose Jira token performs the read', name: 'username')]
        string $username,
        #[Argument(description: 'Ticket system ID', name: 'ticket-system')]
        string $ticketSystem,
        #[Option(description: 'Start date (Y-m-d); default: first day of current month', name: 'from')]
        ?string $from,
        #[Option(description: 'End date (Y-m-d); default: today', name: 'to')]
        ?string $to,
        #[Option(description: 'Only import for these TT usernames (repeatable); default: everyone (shadow users created for unknowns)', name: 'user')]
        array $users,
        #[Option(description: 'Activity ID assigned to imported entries (required)', name: 'default-activity')]
        ?string $defaultActivity,
        #[Option(description: 'Preview only: counters and parked items, no writes', name: 'dry-run')]
        bool $dryRun,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        if (null === $defaultActivity || '' === $defaultActivity) {
            $symfonyStyle->error('--default-activity=<ID> is required (activity assigned to imported entries)');

            return 1;
        }

        $user = $this->managerRegistry->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            $symfonyStyle->error('User not found: ' . $username);

            return 1;
        }

        $system = $this->managerRegistry->getRepository(TicketSystem::class)->find((int) $ticketSystem);
        if (!$system instanceof TicketSystem) {
            $symfonyStyle->error('Ticket system not found: ' . $ticketSystem);

            return 1;
        }

        try {
            $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('first day of this month');
            $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today');
        } catch (Exception) {
            $symfonyStyle->error(sprintf('Invalid date in --from/--to (expected Y-m-d): %s / %s', $from ?? '-', $to ?? '-'));

            return 1;
        }

        $syncRun = $this->importWorklogsService->import($user, $system, $fromDate, $toDate, (int) $defaultActivity, $users, $dryRun);

        $this->render($symfonyStyle, $syncRun);

        return SyncRunStatus::COMPLETED === $syncRun->getStatus() ? Command::SUCCESS : 1;
    }

    private function render(SymfonyStyle $symfonyStyle, SyncRun $syncRun): void
    {
        $symfonyStyle->section(sprintf(
            'Import run #%d — %s (%s to %s)%s',
            $syncRun->getId() ?? 0,
            $syncRun->getStatus()->value,
            (string) ($syncRun->getScope()['from'] ?? '?'),
            (string) ($syncRun->getScope()['to'] ?? '?'),
            ($syncRun->getScope()['dry_run'] ?? false) ? ' [dry-run]' : '',
        ));

        $rows = [];
        foreach ($syncRun->getCounters() as $key => $count) {
            $rows[] = [$key, $count];
        }

        $symfonyStyle->table(['result', 'count'], $rows);

        foreach ($syncRun->getItems() as $item) {
            $symfonyStyle->writeln(sprintf(
                ' <comment>%-22s</comment> %s %s %s',
                $item->getKind()->value,
                $item->getIssueKey() ?? '-',
                null !== $item->getRemoteWorklogId() ? '(worklog ' . $item->getRemoteWorklogId() . ')' : '',
                $item->getReason(),
            ));
        }
    }
}
```

If the `#[Option] array $users` signature needs a default (`array $users = []`) for the attribute resolver, add it — mirror whatever `bin/console` accepts (verify with `docker compose --profile dev exec app-dev php bin/console tt:import-worklogs --help`).

- [ ] **Step 3: Run test to verify it passes**; also smoke `docker compose --profile dev exec app-dev php bin/console list tt` — both commands listed.

- [ ] **Step 4: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:fast
git add src/Command/TtImportWorklogsCommand.php tests/Command/TtImportWorklogsCommandTest.php
git commit -S --signoff -m "feat(sync): tt:import-worklogs command with dry-run preview (ADR-023)"
```

---

### Task 8: ADR update

**Files:**
- Modify: `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md`
- Modify: `docs/adr/README.md` (index row)

- [ ] **Step 1: Update the ADR**

1. Status line → `**Status:** Accepted — design approved 2026-07-09; Phases 1 (verify) + 2 (import) implemented, Phases 3–4 pending.`
2. In §3 (author mapping), append one sentence recording the Phase-2 finding: `Implementation note (Phase 2): TT users have no email column; auto-match compares the Jira author name and the email localpart against TT usernames. Shadow users are ordinary rows with active = false (blocks login and lead assignment) — no dedicated flag column was needed.`
3. Verification point 2 → `~~User.type semantics vs a new shadow-user flag~~ **Resolved (Phase 2):** UserType is UNKNOWN/USER/DEV/PL/ADMIN; shadow = active=false + password null, type stays USER.`
4. README index row → `Accepted (Phases 1–2 done; sync/UI pending)`.

- [ ] **Step 2: Commit**

```bash
git add docs/adr/ADR-023-jira-worklog-bidirectional-sync.md docs/adr/README.md
git commit -S --signoff -m "docs(adr): ADR-023 phase 2 implemented; record author-mapping findings"
```

---

## Phase boundaries (orientation only — NOT this plan)

- **Phase 3:** lease-checked `WorklogWriteService`, `EntryEventSubscriber` upgrade, pull/merge/delete/move, per-ticket-system cursor + designated sync user, `tt:sync-worklogs` cron.
- **Phase 4:** v2 endpoints + PAT scopes, MCP tools, SPA UI (self-service import with preview, admin runs, conflict screen), chunked/resumable HTTP runs (the `SyncRun.continuation` column is reserved for this).
