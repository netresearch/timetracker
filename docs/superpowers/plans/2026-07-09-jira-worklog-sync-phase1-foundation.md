# Jira Worklog Sync — Phase 1: Foundation (Schema, Reconciliation Core, Verify) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the sync-state schema, the pure three-way reconciliation core, Jira worklog read methods, and a read-only `tt:verify-worklogs` console command that reports TT↔Jira divergence per user and date range (ADR-023 use case 4).

**Architecture:** Per [ADR-023](../../adr/ADR-023-jira-worklog-bidirectional-sync.md). Three new tables (`worklog_sync_state`, `sync_runs`, `sync_run_items`) persist the lease base and run reports. A pure `ReconciliationService` implements the decision matrix over normalized `WorklogSnapshot` value objects. Reads go through the **legacy** `JiraOAuthApiService` (wired, dual Server/Cloud) — a deliberate deviation from ADR-023 §5 recorded in Task 10: the refactored stack is container-excluded AND writes a different comment format than production. Verify = reconciliation with all writes disabled; it writes only `sync_runs`/`sync_run_items` rows, never entries, never `worklog_sync_state`, never Jira.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 + Migrations, PHPUnit 13 (attributes, intersection mock types), PHPStan level 10.

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
- Quality gates before EVERY commit: `composer analyze` (PHPStan level 10), `composer analyze:arch` (phpat), `composer cs-fix`, and the test suite. Rector (`composer rector`) can conflict with PHPStan — if it rewrites something PHPStan then rejects, PHPStan wins.
- Tests: unit tests must not touch the database; prefer `self::assertSame()`; new unit tests go under directories already in the `unit` testsuite (`tests/Service`, `tests/Enum`, `tests/ValueObject`, `tests/DTO/Jira`); `tests/Command` and `tests/Repository` belong to the `integration` suite.
- Commits: conventional format, signed: `git commit -S --signoff -m "..."`. No AI attribution.
- Entities extend `App\Model\Base`, use `protected` typed properties (its reflective `toArray()` reads `IS_PROTECTED` props), fluent setters returning `static`, `datetime_immutable` for timestamps.
- Enums: backed string enums in `src/Enum/`, one file per enum.
- `Entry::getDuration()` returns **minutes**; Jira `timeSpentSeconds` = duration × 60.
- The production worklog comment format is `#<entryId>: <activityName>: <description>` (from `JiraOAuthApiService::getTicketSystemWorkLogComment`, `src/Service/Integration/Jira/JiraOAuthApiService.php:749-761`). The projection in this plan MUST reproduce it byte-for-byte, including the fallbacks `'no activity specified'` and `'no description given'` (the latter also when description is the string `'0'`).

---

### Task 1: Sync enums

**Files:**
- Create: `src/Enum/SyncRunType.php`, `src/Enum/SyncRunStatus.php`, `src/Enum/WorklogSyncStatus.php`, `src/Enum/SyncItemKind.php`, `src/Enum/SyncAction.php`, `src/Enum/WorklogField.php`
- Test: `tests/Enum/SyncEnumsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the six enums exactly as defined below; later tasks reference every case by name.

- [ ] **Step 1: Write the failing test**

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\SyncAction;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Enum\WorklogField;
use App\Enum\WorklogSyncStatus;
use PHPUnit\Framework\TestCase;

final class SyncEnumsTest extends TestCase
{
    public function testSyncRunTypeValues(): void
    {
        self::assertSame(['import', 'sync', 'verify'], array_column(SyncRunType::cases(), 'value'));
    }

    public function testSyncRunStatusValues(): void
    {
        self::assertSame(['running', 'partial', 'completed', 'failed'], array_column(SyncRunStatus::cases(), 'value'));
    }

    public function testWorklogSyncStatusValues(): void
    {
        self::assertSame(['in_sync', 'conflict', 'orphaned'], array_column(WorklogSyncStatus::cases(), 'value'));
    }

    public function testSyncItemKindValues(): void
    {
        self::assertSame(
            ['remote_only', 'local_only', 'never_synced', 'diverged', 'local_dirty', 'remote_dirty', 'mergeable', 'conflict', 'probable_duplicate', 'truncated', 'error'],
            array_column(SyncItemKind::cases(), 'value'),
        );
    }

    public function testSyncActionValues(): void
    {
        self::assertSame(
            ['none', 'push', 'pull', 'merge', 'conflict', 'create_local', 'remote_missing', 'diverged'],
            array_column(SyncAction::cases(), 'value'),
        );
    }

    public function testWorklogFieldValues(): void
    {
        self::assertSame(['issue_key', 'started', 'duration', 'comment'], array_column(WorklogField::cases(), 'value'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Enum/SyncEnumsTest.php`
Expected: FAIL — `Class "App\Enum\SyncRunType" not found`.

- [ ] **Step 3: Implement the six enums**

Each in its own file with the license header. Follow `src/Enum/DeploymentType.php` style.

`src/Enum/SyncRunType.php`:
```php
namespace App\Enum;

/**
 * Type of a worklog sync run (ADR-023).
 */
enum SyncRunType: string
{
    case IMPORT = 'import';
    case SYNC = 'sync';
    case VERIFY = 'verify';
}
```

`src/Enum/SyncRunStatus.php`:
```php
namespace App\Enum;

/**
 * Lifecycle status of a worklog sync run (ADR-023).
 */
enum SyncRunStatus: string
{
    case RUNNING = 'running';
    case PARTIAL = 'partial';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
```

`src/Enum/WorklogSyncStatus.php`:
```php
namespace App\Enum;

/**
 * Durable per-entry sync state (ADR-023). Dirty flags are computed, not stored.
 */
enum WorklogSyncStatus: string
{
    case IN_SYNC = 'in_sync';
    case CONFLICT = 'conflict';
    case ORPHANED = 'orphaned';
}
```

`src/Enum/SyncItemKind.php`:
```php
namespace App\Enum;

/**
 * Kind of a noteworthy sync-run item (ADR-023). Routine successes are counters, not items.
 */
enum SyncItemKind: string
{
    case REMOTE_ONLY = 'remote_only';
    case LOCAL_ONLY = 'local_only';
    case NEVER_SYNCED = 'never_synced';
    case DIVERGED = 'diverged';
    case LOCAL_DIRTY = 'local_dirty';
    case REMOTE_DIRTY = 'remote_dirty';
    case MERGEABLE = 'mergeable';
    case CONFLICT = 'conflict';
    case PROBABLE_DUPLICATE = 'probable_duplicate';
    case TRUNCATED = 'truncated';
    case ERROR = 'error';
}
```

`src/Enum/SyncAction.php`:
```php
namespace App\Enum;

/**
 * Decision produced by the reconciliation matrix (ADR-023 §2).
 */
enum SyncAction: string
{
    case NONE = 'none';
    case PUSH = 'push';
    case PULL = 'pull';
    case MERGE = 'merge';
    case CONFLICT = 'conflict';
    case CREATE_LOCAL = 'create_local';
    case REMOTE_MISSING = 'remote_missing';
    case DIVERGED = 'diverged';
}
```

`src/Enum/WorklogField.php`:
```php
namespace App\Enum;

/**
 * The field set a Jira worklog and a TT entry share; only these participate in conflict detection.
 */
enum WorklogField: string
{
    case ISSUE_KEY = 'issue_key';
    case STARTED = 'started';
    case DURATION = 'duration';
    case COMMENT = 'comment';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Enum/SyncEnumsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/Enum/Sync*.php src/Enum/WorklogField.php src/Enum/WorklogSyncStatus.php tests/Enum/SyncEnumsTest.php
git commit -S --signoff -m "feat(sync): add worklog sync enums (ADR-023)"
```

---

### Task 2: Migration + entities + repositories

**Files:**
- Create: `migrations/Version20260709_WorklogSyncFoundation.php`
- Create: `src/Entity/SyncRun.php`, `src/Entity/SyncRunItem.php`, `src/Entity/WorklogSyncState.php`
- Create: `src/Repository/SyncRunRepository.php`, `src/Repository/WorklogSyncStateRepository.php`
- Test: `tests/Entity/SyncRunTest.php`

**Interfaces:**
- Consumes: enums from Task 1.
- Produces: `SyncRun` with `incrementCounter(string $key): void`, `addItem(SyncRunItem $item): static`, fluent setters `setType(SyncRunType)`, `setStatus(SyncRunStatus)`, `setTicketSystem(TicketSystem)`, `setTriggeredBy(User)`, `setScope(array)`, `setCounters(array)`, `setStartedAt(DateTimeImmutable)`, `setFinishedAt(?DateTimeImmutable)`, and getters for each. `SyncRunItem` with fluent setters `setSyncRun`, `setKind(SyncItemKind)`, `setIssueKey(?string)`, `setRemoteWorklogId(?int)`, `setEntry(?Entry)`, `setAuthor(?string)`, `setReason(string)`, `setPayload(?array)`, `setCreatedAt(DateTimeImmutable)`. `WorklogSyncState` with `setEntry(Entry)`, `setTicketSystem(TicketSystem)`, `setStatus(WorklogSyncStatus)`, `setBasePayload(array)`, `setBaseUpdatedAt(string)`, `setConflictRemotePayload(?array)`, `setLastSyncedAt(DateTimeImmutable)`, `setLastSyncRun(?SyncRun)`. `WorklogSyncStateRepository::findByEntryIds(array $entryIds): array<int, WorklogSyncState>` (keyed by entry id).

- [ ] **Step 1: Write the failing entity test**

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SyncRunTest extends TestCase
{
    public function testCountersIncrementFromZero(): void
    {
        $syncRun = new SyncRun();
        $syncRun->setCounters([]);
        $syncRun->incrementCounter('in_sync');
        $syncRun->incrementCounter('in_sync');
        $syncRun->incrementCounter('errors');

        self::assertSame(['in_sync' => 2, 'errors' => 1], $syncRun->getCounters());
    }

    public function testAddItemLinksBothSides(): void
    {
        $syncRun = new SyncRun();
        $item = (new SyncRunItem())
            ->setKind(SyncItemKind::REMOTE_ONLY)
            ->setIssueKey('ABC-1')
            ->setReason('worklog 42 has no matching entry')
            ->setCreatedAt(new DateTimeImmutable('2026-07-09 12:00:00'));

        $syncRun->addItem($item);

        self::assertSame([$item], $syncRun->getItems()->toArray());
        self::assertSame($syncRun, $item->getSyncRun());
    }

    public function testFluentSetters(): void
    {
        $syncRun = (new SyncRun())
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setScope(['from' => '2026-06-01', 'to' => '2026-06-30'])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'));

        self::assertSame(SyncRunType::VERIFY, $syncRun->getType());
        self::assertSame(SyncRunStatus::RUNNING, $syncRun->getStatus());
        self::assertNull($syncRun->getFinishedAt());
    }
}
```

Add `tests/Entity/SyncRunTest.php` to the `unit` testsuite file list in `phpunit.xml.dist` (the unit suite enumerates entity tests by file — insert alphabetically among the existing `<file>tests/Entity/...</file>` lines):

```xml
<file>tests/Entity/SyncRunTest.php</file>
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Entity/SyncRunTest.php`
Expected: FAIL — `Class "App\Entity\SyncRun" not found`.

- [ ] **Step 3: Implement the entities**

`src/Entity/SyncRun.php`:
```php
namespace App\Entity;

use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Model\Base;
use App\Repository\SyncRunRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncRunRepository::class)]
#[ORM\Table(name: 'sync_runs')]
class SyncRun extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: SyncRunType::class)]
    protected SyncRunType $type = SyncRunType::VERIFY;

    #[ORM\Column(type: 'string', length: 16, enumType: SyncRunStatus::class)]
    protected SyncRunStatus $status = SyncRunStatus::RUNNING;

    #[ORM\ManyToOne(targetEntity: TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?TicketSystem $ticketSystem = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'triggered_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?User $triggeredBy = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    protected array $scope = [];

    /** @var array<string, int> */
    #[ORM\Column(type: 'json')]
    protected array $counters = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $continuation = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, SyncRunItem> */
    #[ORM\OneToMany(mappedBy: 'syncRun', targetEntity: SyncRunItem::class, cascade: ['persist'])]
    protected Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): SyncRunType
    {
        return $this->type;
    }

    public function setType(SyncRunType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): SyncRunStatus
    {
        return $this->status;
    }

    public function setStatus(SyncRunStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    public function setTicketSystem(TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(User $user): static
    {
        $this->triggeredBy = $user;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getScope(): array
    {
        return $this->scope;
    }

    /** @param array<string, mixed> $scope */
    public function setScope(array $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    /** @return array<string, int> */
    public function getCounters(): array
    {
        return $this->counters;
    }

    /** @param array<string, int> $counters */
    public function setCounters(array $counters): static
    {
        $this->counters = $counters;

        return $this;
    }

    public function incrementCounter(string $key): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    /** @return array<string, mixed>|null */
    public function getContinuation(): ?array
    {
        return $this->continuation;
    }

    /** @param array<string, mixed>|null $continuation */
    public function setContinuation(?array $continuation): static
    {
        $this->continuation = $continuation;

        return $this;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, SyncRunItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(SyncRunItem $item): static
    {
        $this->items->add($item);
        $item->setSyncRun($this);

        return $this;
    }
}
```

`src/Entity/SyncRunItem.php`:
```php
namespace App\Entity;

use App\Enum\SyncItemKind;
use App\Model\Base;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sync_run_items')]
#[ORM\Index(name: 'idx_sync_run_items_run_kind', columns: ['sync_run_id', 'kind'])]
class SyncRunItem extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SyncRun::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'sync_run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?SyncRun $syncRun = null;

    #[ORM\Column(type: 'string', length: 32, enumType: SyncItemKind::class)]
    protected SyncItemKind $kind = SyncItemKind::ERROR;

    #[ORM\Column(name: 'issue_key', type: 'string', length: 50, nullable: true)]
    protected ?string $issueKey = null;

    #[ORM\Column(name: 'remote_worklog_id', type: 'bigint', nullable: true)]
    protected ?int $remoteWorklogId = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Entry $entry = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $author = null;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $reason = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $payload = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSyncRun(): ?SyncRun
    {
        return $this->syncRun;
    }

    public function setSyncRun(SyncRun $syncRun): static
    {
        $this->syncRun = $syncRun;

        return $this;
    }

    public function getKind(): SyncItemKind
    {
        return $this->kind;
    }

    public function setKind(SyncItemKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getIssueKey(): ?string
    {
        return $this->issueKey;
    }

    public function setIssueKey(?string $issueKey): static
    {
        $this->issueKey = $issueKey;

        return $this;
    }

    public function getRemoteWorklogId(): ?int
    {
        return $this->remoteWorklogId;
    }

    public function setRemoteWorklogId(?int $remoteWorklogId): static
    {
        $this->remoteWorklogId = $remoteWorklogId;

        return $this;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    public function setEntry(?Entry $entry): static
    {
        $this->entry = $entry;

        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /** @param array<string, mixed>|null $payload */
    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
```

`src/Entity/WorklogSyncState.php`:
```php
namespace App\Entity;

use App\Enum\WorklogSyncStatus;
use App\Model\Base;
use App\Repository\WorklogSyncStateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lease base state for one entry's Jira worklog (ADR-023 §2).
 */
#[ORM\Entity(repositoryClass: WorklogSyncStateRepository::class)]
#[ORM\Table(name: 'worklog_sync_state')]
class WorklogSyncState extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    protected ?Entry $entry = null;

    #[ORM\ManyToOne(targetEntity: TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?TicketSystem $ticketSystem = null;

    #[ORM\Column(type: 'string', length: 16, enumType: WorklogSyncStatus::class)]
    protected WorklogSyncStatus $status = WorklogSyncStatus::IN_SYNC;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'base_payload', type: 'json')]
    protected array $basePayload = [];

    /**
     * Raw Jira `updated` string at last sync — compared verbatim for the lease (CAS).
     */
    #[ORM\Column(name: 'base_updated_at', type: 'string', length: 40)]
    protected string $baseUpdatedAt = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'conflict_remote_payload', type: 'json', nullable: true)]
    protected ?array $conflictRemotePayload = null;

    #[ORM\Column(name: 'last_synced_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $lastSyncedAt = null;

    #[ORM\ManyToOne(targetEntity: SyncRun::class)]
    #[ORM\JoinColumn(name: 'last_sync_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?SyncRun $lastSyncRun = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    public function setEntry(Entry $entry): static
    {
        $this->entry = $entry;

        return $this;
    }

    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    public function setTicketSystem(TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getStatus(): WorklogSyncStatus
    {
        return $this->status;
    }

    public function setStatus(WorklogSyncStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getBasePayload(): array
    {
        return $this->basePayload;
    }

    /** @param array<string, mixed> $basePayload */
    public function setBasePayload(array $basePayload): static
    {
        $this->basePayload = $basePayload;

        return $this;
    }

    public function getBaseUpdatedAt(): string
    {
        return $this->baseUpdatedAt;
    }

    public function setBaseUpdatedAt(string $baseUpdatedAt): static
    {
        $this->baseUpdatedAt = $baseUpdatedAt;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getConflictRemotePayload(): ?array
    {
        return $this->conflictRemotePayload;
    }

    /** @param array<string, mixed>|null $conflictRemotePayload */
    public function setConflictRemotePayload(?array $conflictRemotePayload): static
    {
        $this->conflictRemotePayload = $conflictRemotePayload;

        return $this;
    }

    public function getLastSyncedAt(): ?DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getLastSyncRun(): ?SyncRun
    {
        return $this->lastSyncRun;
    }

    public function setLastSyncRun(?SyncRun $syncRun): static
    {
        $this->lastSyncRun = $syncRun;

        return $this;
    }
}
```

- [ ] **Step 4: Implement the repositories**

`src/Repository/SyncRunRepository.php`:
```php
namespace App\Repository;

use App\Entity\SyncRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncRun>
 */
class SyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRun::class);
    }
}
```

`src/Repository/WorklogSyncStateRepository.php`:
```php
namespace App\Repository;

use App\Entity\WorklogSyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorklogSyncState>
 */
class WorklogSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorklogSyncState::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, WorklogSyncState> keyed by entry id
     */
    public function findByEntryIds(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        /** @var list<WorklogSyncState> $states */
        $states = $this->createQueryBuilder('s')
            ->where('s.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getResult();

        $byEntryId = [];
        foreach ($states as $state) {
            $entry = $state->getEntry();
            if (null !== $entry && null !== $entry->getId()) {
                $byEntryId[$entry->getId()] = $state;
            }
        }

        return $byEntryId;
    }
}
```

- [ ] **Step 5: Write the migration**

`migrations/Version20260709_WorklogSyncFoundation.php` — follow `Version20260704_AddProjectSubticketsSyncedAt.php` style (license header, `DoctrineMigrations` namespace, `final class`):

```php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709_WorklogSyncFoundation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023: sync_runs + sync_run_items (run reports) and worklog_sync_state (lease base) tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sync_runs (
                id INT AUTO_INCREMENT NOT NULL,
                ticket_system_id INT NOT NULL,
                triggered_by_id INT NOT NULL,
                type VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                scope JSON NOT NULL,
                counters JSON NOT NULL,
                continuation JSON DEFAULT NULL,
                started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_sync_runs_ticket_system (ticket_system_id),
                INDEX idx_sync_runs_triggered_by (triggered_by_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_sync_runs_ticket_system FOREIGN KEY (ticket_system_id) REFERENCES ticket_systems (id) ON DELETE CASCADE,
                CONSTRAINT fk_sync_runs_triggered_by FOREIGN KEY (triggered_by_id) REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sync_run_items (
                id INT AUTO_INCREMENT NOT NULL,
                sync_run_id INT NOT NULL,
                entry_id INT DEFAULT NULL,
                kind VARCHAR(32) NOT NULL,
                issue_key VARCHAR(50) DEFAULT NULL,
                remote_worklog_id BIGINT DEFAULT NULL,
                author VARCHAR(255) DEFAULT NULL,
                reason VARCHAR(255) NOT NULL,
                payload JSON DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_sync_run_items_run_kind (sync_run_id, kind),
                INDEX idx_sync_run_items_entry (entry_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_sync_run_items_run FOREIGN KEY (sync_run_id) REFERENCES sync_runs (id) ON DELETE CASCADE,
                CONSTRAINT fk_sync_run_items_entry FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE SET NULL
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE worklog_sync_state (
                id INT AUTO_INCREMENT NOT NULL,
                entry_id INT NOT NULL,
                ticket_system_id INT NOT NULL,
                last_sync_run_id INT DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                base_payload JSON NOT NULL,
                base_updated_at VARCHAR(40) NOT NULL,
                conflict_remote_payload JSON DEFAULT NULL,
                last_synced_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_worklog_sync_state_entry (entry_id),
                INDEX idx_worklog_sync_state_status (status),
                PRIMARY KEY (id),
                CONSTRAINT fk_wss_entry FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
                CONSTRAINT fk_wss_ticket_system FOREIGN KEY (ticket_system_id) REFERENCES ticket_systems (id) ON DELETE CASCADE,
                CONSTRAINT fk_wss_last_run FOREIGN KEY (last_sync_run_id) REFERENCES sync_runs (id) ON DELETE SET NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worklog_sync_state');
        $this->addSql('DROP TABLE sync_run_items');
        $this->addSql('DROP TABLE sync_runs');
    }
}
```

- [ ] **Step 6: Run migration and validate schema**

```bash
docker compose --profile dev exec app-dev php bin/console doctrine:migrations:migrate --no-interaction
docker compose --profile dev exec app-dev php bin/console doctrine:schema:validate
```
Expected: migration applies; `doctrine:schema:validate` reports mapping and database in sync (if it flags a type mismatch, align the entity attribute — the entity is the source of truth for naming, the migration for DDL).

- [ ] **Step 7: Run the entity test**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Entity/SyncRunTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add migrations/Version20260709_WorklogSyncFoundation.php src/Entity/SyncRun.php src/Entity/SyncRunItem.php src/Entity/WorklogSyncState.php src/Repository/SyncRunRepository.php src/Repository/WorklogSyncStateRepository.php tests/Entity/SyncRunTest.php phpunit.xml.dist
git commit -S --signoff -m "feat(sync): sync run + worklog sync state schema and entities (ADR-023)"
```

---

### Task 3: WorklogSnapshot value object + comment codec

**Files:**
- Create: `src/ValueObject/Sync/WorklogSnapshot.php`, `src/Service/Sync/WorklogCommentCodec.php`
- Test: `tests/ValueObject/Sync/WorklogSnapshotTest.php`, `tests/Service/Sync/WorklogCommentCodecTest.php`

**Interfaces:**
- Consumes: `WorklogField` enum (Task 1).
- Produces: `WorklogSnapshot` readonly VO with ctor `(string $issueKey, int $startedTimestamp, int $durationMinutes, string $comment)`, `equals(self): bool`, `diff(self): list<WorklogField>`, `toArray(): array`, `fromArray(array): self`. `WorklogCommentCodec::encode(?int $entryId, ?string $activityName, string $description): string` and `WorklogCommentCodec::normalize(string $comment): string`.

- [ ] **Step 1: Write the failing tests**

`tests/ValueObject/Sync/WorklogSnapshotTest.php`:
```php
namespace Tests\ValueObject\Sync;

use App\Enum\WorklogField;
use App\ValueObject\Sync\WorklogSnapshot;
use PHPUnit\Framework\TestCase;

final class WorklogSnapshotTest extends TestCase
{
    private function snapshot(string $issueKey = 'ABC-1', int $started = 1751871600, int $minutes = 60, string $comment = '#5: Development: fixed it'): WorklogSnapshot
    {
        return new WorklogSnapshot($issueKey, $started, $minutes, $comment);
    }

    public function testEqualSnapshotsHaveEmptyDiff(): void
    {
        self::assertTrue($this->snapshot()->equals($this->snapshot()));
        self::assertSame([], $this->snapshot()->diff($this->snapshot()));
    }

    public function testDiffListsEveryChangedField(): void
    {
        $diff = $this->snapshot()->diff($this->snapshot(issueKey: 'ABC-2', minutes: 90));

        self::assertSame([WorklogField::ISSUE_KEY, WorklogField::DURATION], $diff);
    }

    public function testDiffDetectsStartedAndComment(): void
    {
        $diff = $this->snapshot()->diff($this->snapshot(started: 1751875200, comment: 'other'));

        self::assertSame([WorklogField::STARTED, WorklogField::COMMENT], $diff);
    }

    public function testArrayRoundTrip(): void
    {
        $snapshot = $this->snapshot();

        self::assertTrue(WorklogSnapshot::fromArray($snapshot->toArray())->equals($snapshot));
        self::assertSame(
            ['issue_key' => 'ABC-1', 'started_ts' => 1751871600, 'duration_minutes' => 60, 'comment' => '#5: Development: fixed it'],
            $snapshot->toArray(),
        );
    }
}
```

`tests/Service/Sync/WorklogCommentCodecTest.php` — the encode format MUST match `JiraOAuthApiService::getTicketSystemWorkLogComment()` exactly:
```php
namespace Tests\Service\Sync;

use App\Service\Sync\WorklogCommentCodec;
use PHPUnit\Framework\TestCase;

final class WorklogCommentCodecTest extends TestCase
{
    private WorklogCommentCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new WorklogCommentCodec();
    }

    public function testEncodeMatchesProductionPushFormat(): void
    {
        self::assertSame('#42: Development: fixed the bug', $this->codec->encode(42, 'Development', 'fixed the bug'));
    }

    public function testEncodeFallbacksMatchLegacyService(): void
    {
        self::assertSame('#42: no activity specified: no description given', $this->codec->encode(42, null, ''));
        self::assertSame('#42: Development: no description given', $this->codec->encode(42, 'Development', '0'));
    }

    public function testNormalizeTrimsAndUnifiesLineEndings(): void
    {
        self::assertSame("a\nb", WorklogCommentCodec::normalize("  a\r\nb \n"));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/ValueObject/Sync tests/Service/Sync`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`src/ValueObject/Sync/WorklogSnapshot.php`:
```php
namespace App\ValueObject\Sync;

use App\Enum\WorklogField;
use InvalidArgumentException;

use function is_int;
use function is_string;

/**
 * Normalized projection of a worklog — the shared TT↔Jira field set (ADR-023 §2).
 * Producers (projector/normalizer) apply all normalization; comparison here is exact.
 */
final readonly class WorklogSnapshot
{
    public function __construct(
        public string $issueKey,
        public int $startedTimestamp,
        public int $durationMinutes,
        public string $comment,
    ) {
    }

    public function equals(self $other): bool
    {
        return [] === $this->diff($other);
    }

    /**
     * @return list<WorklogField>
     */
    public function diff(self $other): array
    {
        $fields = [];
        if ($this->issueKey !== $other->issueKey) {
            $fields[] = WorklogField::ISSUE_KEY;
        }

        if ($this->startedTimestamp !== $other->startedTimestamp) {
            $fields[] = WorklogField::STARTED;
        }

        if ($this->durationMinutes !== $other->durationMinutes) {
            $fields[] = WorklogField::DURATION;
        }

        if ($this->comment !== $other->comment) {
            $fields[] = WorklogField::COMMENT;
        }

        return $fields;
    }

    /**
     * @return array{issue_key: string, started_ts: int, duration_minutes: int, comment: string}
     */
    public function toArray(): array
    {
        return [
            'issue_key' => $this->issueKey,
            'started_ts' => $this->startedTimestamp,
            'duration_minutes' => $this->durationMinutes,
            'comment' => $this->comment,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['issue_key'], $data['started_ts'], $data['duration_minutes'], $data['comment'])
            || !is_string($data['issue_key']) || !is_int($data['started_ts'])
            || !is_int($data['duration_minutes']) || !is_string($data['comment'])
        ) {
            throw new InvalidArgumentException('Invalid worklog snapshot payload');
        }

        return new self($data['issue_key'], $data['started_ts'], $data['duration_minutes'], $data['comment']);
    }
}
```

`src/Service/Sync/WorklogCommentCodec.php`:
```php
namespace App\Service\Sync;

/**
 * Reproduces the worklog comment the production push writes
 * (JiraOAuthApiService::getTicketSystemWorkLogComment): "#<entryId>: <activity>: <description>".
 * The diff MUST use this exact projection or no worklog would ever compare as in-sync.
 */
class WorklogCommentCodec
{
    public function encode(?int $entryId, ?string $activityName, string $description): string
    {
        $activity = $activityName ?? 'no activity specified';

        if ('' === $description || '0' === $description) {
            $description = 'no description given';
        }

        return '#' . ($entryId ?? 0) . ': ' . $activity . ': ' . $description;
    }

    public static function normalize(string $comment): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $comment));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/ValueObject/Sync tests/Service/Sync`
Expected: PASS (7 tests).

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/ValueObject/Sync src/Service/Sync tests/ValueObject/Sync tests/Service/Sync
git commit -S --signoff -m "feat(sync): worklog snapshot value object and comment codec (ADR-023)"
```

---

### Task 4: JiraWorkLog DTO extension (author + updated)

**Files:**
- Modify: `src/DTO/Jira/JiraWorkLog.php`
- Create: `src/DTO/Jira/JiraUserIdentity.php`
- Test: modify `tests/DTO/Jira/JiraWorkLogTest.php` (add tests; do not touch existing ones), create `tests/DTO/Jira/JiraUserIdentityTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `JiraWorkLog` gains readonly props `?string $updated`, `?string $authorAccountId`, `?string $authorName`, `?string $authorEmail` (appended after `$timeSpentSeconds`, all defaulting to null — existing positional construction keeps working). `JiraUserIdentity` readonly DTO `(?string $accountId, ?string $name, ?string $email)` with `fromApiResponse(object): self` and `matchesWorklogAuthor(JiraWorkLog): bool`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/DTO/Jira/JiraWorkLogTest.php`:
```php
public function testFromApiResponseParsesAuthorAndUpdated(): void
{
    $response = (object) [
        'id' => '1001',
        'comment' => 'work',
        'started' => '2026-07-08T09:00:00.000+0200',
        'timeSpentSeconds' => 3600,
        'updated' => '2026-07-08T10:15:30.000+0200',
        'author' => (object) [
            'accountId' => 'abc-123',
            'name' => 'jdoe',
            'emailAddress' => 'jdoe@example.com',
        ],
    ];

    $workLog = JiraWorkLog::fromApiResponse($response);

    self::assertSame('2026-07-08T10:15:30.000+0200', $workLog->updated);
    self::assertSame('abc-123', $workLog->authorAccountId);
    self::assertSame('jdoe', $workLog->authorName);
    self::assertSame('jdoe@example.com', $workLog->authorEmail);
}

public function testFromApiResponseWithoutAuthorYieldsNulls(): void
{
    $workLog = JiraWorkLog::fromApiResponse((object) ['id' => 1]);

    self::assertNull($workLog->updated);
    self::assertNull($workLog->authorAccountId);
    self::assertNull($workLog->authorName);
    self::assertNull($workLog->authorEmail);
}
```

New `tests/DTO/Jira/JiraUserIdentityTest.php`:
```php
namespace Tests\DTO\Jira;

use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use PHPUnit\Framework\TestCase;

final class JiraUserIdentityTest extends TestCase
{
    public function testFromApiResponse(): void
    {
        $identity = JiraUserIdentity::fromApiResponse((object) [
            'accountId' => 'abc-123',
            'name' => 'jdoe',
            'emailAddress' => 'JDoe@Example.com',
        ]);

        self::assertSame('abc-123', $identity->accountId);
        self::assertSame('jdoe', $identity->name);
        self::assertSame('JDoe@Example.com', $identity->email);
    }

    public function testMatchesByAccountId(): void
    {
        $identity = new JiraUserIdentity(accountId: 'abc-123');
        $workLog = new JiraWorkLog(id: 1, authorAccountId: 'abc-123');

        self::assertTrue($identity->matchesWorklogAuthor($workLog));
    }

    public function testMatchesByEmailCaseInsensitive(): void
    {
        $identity = new JiraUserIdentity(email: 'jdoe@example.com');
        $workLog = new JiraWorkLog(id: 1, authorEmail: 'JDOE@example.com');

        self::assertTrue($identity->matchesWorklogAuthor($workLog));
    }

    public function testNoMatchWhenNothingOverlaps(): void
    {
        $identity = new JiraUserIdentity(accountId: 'abc-123', name: 'jdoe');
        $workLog = new JiraWorkLog(id: 1, authorAccountId: 'other', authorName: 'someone');

        self::assertFalse($identity->matchesWorklogAuthor($workLog));
    }

    public function testNullSidesNeverMatch(): void
    {
        self::assertFalse((new JiraUserIdentity())->matchesWorklogAuthor(new JiraWorkLog(id: 1)));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/DTO/Jira`
Expected: FAIL — unknown named arguments / class `JiraUserIdentity` not found. Pre-existing `JiraWorkLogTest` tests still pass.

- [ ] **Step 3: Extend `JiraWorkLog` and add `JiraUserIdentity`**

`src/DTO/Jira/JiraWorkLog.php` — add four constructor properties AFTER the existing ones and extend `fromApiResponse`:
```php
public function __construct(
    public ?int $id = null,
    public ?string $self = null,
    public ?string $comment = null,
    public ?string $started = null,
    public ?int $timeSpentSeconds = null,
    public ?string $updated = null,
    public ?string $authorAccountId = null,
    public ?string $authorName = null,
    public ?string $authorEmail = null,
) {
}

public static function fromApiResponse(object $response): self
{
    /** @var array<string, mixed> $data */
    $data = (array) $response;

    /** @var array<string, mixed> $author */
    $author = isset($data['author']) && is_object($data['author']) ? (array) $data['author'] : [];

    return new self(
        id: isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : null,
        self: isset($data['self']) && is_string($data['self']) ? $data['self'] : null,
        comment: isset($data['comment']) && is_string($data['comment']) ? $data['comment'] : null,
        started: isset($data['started']) && is_string($data['started']) ? $data['started'] : null,
        timeSpentSeconds: isset($data['timeSpentSeconds']) && is_scalar($data['timeSpentSeconds']) ? (int) $data['timeSpentSeconds'] : null,
        updated: isset($data['updated']) && is_string($data['updated']) ? $data['updated'] : null,
        authorAccountId: isset($author['accountId']) && is_string($author['accountId']) ? $author['accountId'] : null,
        authorName: isset($author['name']) && is_string($author['name']) ? $author['name'] : null,
        authorEmail: isset($author['emailAddress']) && is_string($author['emailAddress']) ? $author['emailAddress'] : null,
    );
}
```

`src/DTO/Jira/JiraUserIdentity.php`:
```php
namespace App\DTO\Jira;

use function is_string;
use function strcasecmp;

/**
 * The Jira account behind a token (GET /rest/api/2/myself) — used to filter worklogs by author.
 */
final readonly class JiraUserIdentity
{
    public function __construct(
        public ?string $accountId = null,
        public ?string $name = null,
        public ?string $email = null,
    ) {
    }

    public static function fromApiResponse(object $response): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $response;

        return new self(
            accountId: isset($data['accountId']) && is_string($data['accountId']) ? $data['accountId'] : null,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            email: isset($data['emailAddress']) && is_string($data['emailAddress']) ? $data['emailAddress'] : null,
        );
    }

    public function matchesWorklogAuthor(JiraWorkLog $workLog): bool
    {
        if (null !== $this->accountId && null !== $workLog->authorAccountId) {
            return $this->accountId === $workLog->authorAccountId;
        }

        if (null !== $this->name && null !== $workLog->authorName) {
            return 0 === strcasecmp($this->name, $workLog->authorName);
        }

        if (null !== $this->email && null !== $workLog->authorEmail) {
            return 0 === strcasecmp($this->email, $workLog->authorEmail);
        }

        return false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/DTO/Jira`
Expected: PASS, including all pre-existing `JiraWorkLogTest` tests.

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/DTO/Jira tests/DTO/Jira
git commit -S --signoff -m "feat(jira): parse worklog author and updated timestamp; add JiraUserIdentity DTO"
```

---

### Task 5: Entry projector + remote normalizer

**Files:**
- Create: `src/Service/Sync/EntryWorklogProjector.php`, `src/Service/Sync/RemoteWorklogNormalizer.php`
- Test: `tests/Service/Sync/EntryWorklogProjectorTest.php`, `tests/Service/Sync/RemoteWorklogNormalizerTest.php`

**Interfaces:**
- Consumes: `WorklogSnapshot`, `WorklogCommentCodec` (Task 3), `JiraWorkLog` (Task 4), `Entry` getters (`getTicket(): string`, `getDay(): DateTimeInterface`, `getStart(): DateTimeInterface`, `getDuration(): int` minutes, `getDescription(): string`, `getActivity(): ?Activity`, `getId(): ?int`).
- Produces: `EntryWorklogProjector::project(Entry $entry): WorklogSnapshot`; `RemoteWorklogNormalizer::normalize(JiraWorkLog $workLog, string $issueKey): WorklogSnapshot` (throws `InvalidArgumentException` when `started` is missing/unparseable — callers record an ERROR item).

- [ ] **Step 1: Write the failing tests**

`tests/Service/Sync/EntryWorklogProjectorTest.php` — the `started` timestamp must equal what the legacy push produces (`getTicketSystemWorkLogStartDate`: day's date + start's H:i in server TZ):
```php
namespace Tests\Service\Sync;

use App\Entity\Activity;
use App\Entity\Entry;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\WorklogCommentCodec;
use DateTime;
use PHPUnit\Framework\TestCase;

final class EntryWorklogProjectorTest extends TestCase
{
    private EntryWorklogProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new EntryWorklogProjector(new WorklogCommentCodec());
    }

    private function entryStub(): Entry
    {
        $activity = self::createStub(Activity::class);
        $activity->method('getName')->willReturn('Development');

        $entry = self::createStub(Entry::class);
        $entry->method('getId')->willReturn(42);
        $entry->method('getTicket')->willReturn('ABC-1');
        $entry->method('getDay')->willReturn(new DateTime('2026-07-08'));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 09:30:00'));
        $entry->method('getDuration')->willReturn(90);
        $entry->method('getDescription')->willReturn('fixed it');
        $entry->method('getActivity')->willReturn($activity);

        return $entry;
    }

    public function testProjectionUsesDayPlusStartTime(): void
    {
        $snapshot = $this->projector->project($this->entryStub());

        self::assertSame('ABC-1', $snapshot->issueKey);
        self::assertSame((new DateTime('2026-07-08 09:30:00'))->getTimestamp(), $snapshot->startedTimestamp);
        self::assertSame(90, $snapshot->durationMinutes);
        self::assertSame('#42: Development: fixed it', $snapshot->comment);
    }
}
```

`tests/Service/Sync/RemoteWorklogNormalizerTest.php`:
```php
namespace Tests\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Service\Sync\RemoteWorklogNormalizer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RemoteWorklogNormalizerTest extends TestCase
{
    private RemoteWorklogNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RemoteWorklogNormalizer();
    }

    public function testNormalizeParsesOffsetTimestampAndSeconds(): void
    {
        $workLog = new JiraWorkLog(id: 7, comment: " work done\r\n", started: '2026-07-08T09:30:00.000+0200', timeSpentSeconds: 5400);

        $snapshot = $this->normalizer->normalize($workLog, 'ABC-1');

        self::assertSame('ABC-1', $snapshot->issueKey);
        self::assertSame((new DateTimeImmutable('2026-07-08T09:30:00.000+0200'))->getTimestamp(), $snapshot->startedTimestamp);
        self::assertSame(90, $snapshot->durationMinutes);
        self::assertSame('work done', $snapshot->comment);
    }

    public function testSecondsRoundHalfUpToMinutes(): void
    {
        $up = new JiraWorkLog(id: 1, started: '2026-07-08T09:00:00.000+0000', timeSpentSeconds: 90);
        $down = new JiraWorkLog(id: 2, started: '2026-07-08T09:00:00.000+0000', timeSpentSeconds: 89);

        self::assertSame(2, $this->normalizer->normalize($up, 'A-1')->durationMinutes);
        self::assertSame(1, $this->normalizer->normalize($down, 'A-1')->durationMinutes);
    }

    public function testMissingStartedThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->normalize(new JiraWorkLog(id: 1, timeSpentSeconds: 60), 'A-1');
    }

    public function testNullCommentBecomesEmptyString(): void
    {
        $workLog = new JiraWorkLog(id: 1, started: '2026-07-08T09:00:00.000+0000', timeSpentSeconds: 60);

        self::assertSame('', $this->normalizer->normalize($workLog, 'A-1')->comment);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync`
Expected: FAIL — classes not found (codec tests from Task 3 still pass).

- [ ] **Step 3: Implement**

`src/Service/Sync/EntryWorklogProjector.php`:
```php
namespace App\Service\Sync;

use App\Entity\Activity;
use App\Entity\Entry;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTime;

/**
 * Projects a TT entry into the shared worklog field set, mirroring exactly what
 * the production push (JiraOAuthApiService) would write to Jira (ADR-023 §2).
 */
class EntryWorklogProjector
{
    public function __construct(private readonly WorklogCommentCodec $worklogCommentCodec)
    {
    }

    public function project(Entry $entry): WorklogSnapshot
    {
        $started = DateTime::createFromInterface($entry->getDay());
        $started->setTime(
            (int) $entry->getStart()->format('H'),
            (int) $entry->getStart()->format('i'),
        );

        $activity = $entry->getActivity();

        $comment = $this->worklogCommentCodec->encode(
            $entry->getId(),
            $activity instanceof Activity ? $activity->getName() : null,
            $entry->getDescription(),
        );

        return new WorklogSnapshot(
            issueKey: $entry->getTicket(),
            startedTimestamp: $started->getTimestamp(),
            durationMinutes: $entry->getDuration(),
            comment: WorklogCommentCodec::normalize($comment),
        );
    }
}
```

`src/Service/Sync/RemoteWorklogNormalizer.php`:
```php
namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

use function intdiv;

/**
 * Normalizes a Jira worklog into the shared field set (ADR-023 §2):
 * offset-aware timestamp, seconds rounded half-up to minutes, normalized comment.
 */
class RemoteWorklogNormalizer
{
    public function normalize(JiraWorkLog $jiraWorkLog, string $issueKey): WorklogSnapshot
    {
        if (null === $jiraWorkLog->started || '' === $jiraWorkLog->started) {
            throw new InvalidArgumentException('Jira worklog ' . ($jiraWorkLog->id ?? 0) . ' has no started timestamp');
        }

        try {
            $started = new DateTimeImmutable($jiraWorkLog->started);
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Unparseable started timestamp: ' . $jiraWorkLog->started, 0, $exception);
        }

        return new WorklogSnapshot(
            issueKey: $issueKey,
            startedTimestamp: $started->getTimestamp(),
            durationMinutes: intdiv(($jiraWorkLog->timeSpentSeconds ?? 0) + 30, 60),
            comment: WorklogCommentCodec::normalize($jiraWorkLog->comment ?? ''),
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync`
Expected: PASS.

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Sync tests/Service/Sync
git commit -S --signoff -m "feat(sync): entry projector and remote worklog normalizer (ADR-023)"
```

---

### Task 6: ReconciliationService — the decision matrix

**Files:**
- Create: `src/ValueObject/Sync/ReconciliationDecision.php`, `src/Service/Sync/ReconciliationService.php`
- Test: `tests/Service/Sync/ReconciliationServiceTest.php`

**Interfaces:**
- Consumes: `WorklogSnapshot`, `SyncAction`, `WorklogField`.
- Produces: `ReconciliationService::reconcile(?WorklogSnapshot $base, ?WorklogSnapshot $local, ?WorklogSnapshot $remote): ReconciliationDecision`. `ReconciliationDecision` readonly: `(SyncAction $action, list<WorklogField> $fields = [], string $reason = '')`.

- [ ] **Step 1: Write the failing table-driven test — one case per matrix row**

```php
namespace Tests\Service\Sync;

use App\Enum\SyncAction;
use App\Enum\WorklogField;
use App\Service\Sync\ReconciliationService;
use App\ValueObject\Sync\WorklogSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReconciliationServiceTest extends TestCase
{
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->service = new ReconciliationService();
    }

    private static function snap(string $key = 'ABC-1', int $ts = 1751871600, int $min = 60, string $comment = 'c'): WorklogSnapshot
    {
        return new WorklogSnapshot($key, $ts, $min, $comment);
    }

    /**
     * @return array<string, array{0: ?WorklogSnapshot, 1: ?WorklogSnapshot, 2: ?WorklogSnapshot, 3: SyncAction, 4: list<WorklogField>}>
     */
    public static function matrixProvider(): array
    {
        $base = self::snap();

        return [
            'nothing on either side' => [null, null, null, SyncAction::NONE, []],
            'remote only -> create local' => [null, null, self::snap(), SyncAction::CREATE_LOCAL, []],
            'local only, linked -> remote missing' => [$base, self::snap(), null, SyncAction::REMOTE_MISSING, []],
            'local only, no base -> remote missing' => [null, self::snap(), null, SyncAction::REMOTE_MISSING, []],
            'no base, both equal -> none' => [null, self::snap(), self::snap(), SyncAction::NONE, []],
            'no base, differ -> diverged' => [null, self::snap(), self::snap(min: 90), SyncAction::DIVERGED, [WorklogField::DURATION]],
            'clean clean -> none' => [$base, self::snap(), self::snap(), SyncAction::NONE, []],
            'local dirty, remote clean -> push' => [$base, self::snap(min: 90), self::snap(), SyncAction::PUSH, [WorklogField::DURATION]],
            'remote dirty, local clean -> pull' => [$base, self::snap(), self::snap(comment: 'edited'), SyncAction::PULL, [WorklogField::COMMENT]],
            'both dirty, disjoint fields -> merge' => [$base, self::snap(min: 90), self::snap(comment: 'edited'), SyncAction::MERGE, [WorklogField::DURATION, WorklogField::COMMENT]],
            'both dirty, same field -> conflict' => [$base, self::snap(min: 90), self::snap(min: 120), SyncAction::CONFLICT, [WorklogField::DURATION]],
            'both dirty, overlapping field set -> conflict' => [$base, self::snap(min: 90, comment: 'mine'), self::snap(comment: 'theirs'), SyncAction::CONFLICT, [WorklogField::COMMENT]],
            'both changed identically -> none' => [$base, self::snap(min: 90), self::snap(min: 90), SyncAction::NONE, []],
        ];
    }

    /**
     * @param list<WorklogField> $expectedFields
     */
    #[DataProvider('matrixProvider')]
    public function testMatrix(?WorklogSnapshot $base, ?WorklogSnapshot $local, ?WorklogSnapshot $remote, SyncAction $expectedAction, array $expectedFields): void
    {
        $decision = $this->service->reconcile($base, $local, $remote);

        self::assertSame($expectedAction, $decision->action);
        self::assertSame($expectedFields, $decision->fields);
    }
}
```

Note the deliberate edge case `both changed identically -> none`: both sides dirty against base but now equal to each other — nothing to do, the base is simply stale.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/ReconciliationServiceTest.php`
Expected: FAIL — `ReconciliationService` not found.

- [ ] **Step 3: Implement**

`src/ValueObject/Sync/ReconciliationDecision.php`:
```php
namespace App\ValueObject\Sync;

use App\Enum\SyncAction;
use App\Enum\WorklogField;

final readonly class ReconciliationDecision
{
    /**
     * @param list<WorklogField> $fields
     */
    public function __construct(
        public SyncAction $action,
        public array $fields = [],
        public string $reason = '',
    ) {
    }
}
```

`src/Service/Sync/ReconciliationService.php`:
```php
namespace App\Service\Sync;

use App\Enum\SyncAction;
use App\Enum\WorklogField;
use App\ValueObject\Sync\ReconciliationDecision;
use App\ValueObject\Sync\WorklogSnapshot;

use function array_intersect;
use function array_unique;
use function array_values;

/**
 * The ADR-023 §2 decision matrix. Pure: no I/O, no persistence, fully unit-testable.
 */
class ReconciliationService
{
    public function reconcile(?WorklogSnapshot $base, ?WorklogSnapshot $local, ?WorklogSnapshot $remote): ReconciliationDecision
    {
        if (null === $local && null === $remote) {
            return new ReconciliationDecision(SyncAction::NONE, [], 'nothing on either side');
        }

        if (null === $local) {
            return new ReconciliationDecision(SyncAction::CREATE_LOCAL, [], 'remote worklog has no matching entry');
        }

        if (null === $remote) {
            return new ReconciliationDecision(SyncAction::REMOTE_MISSING, [], 'linked remote worklog not found');
        }

        if (null === $base) {
            $diff = $local->diff($remote);
            if ([] === $diff) {
                return new ReconciliationDecision(SyncAction::NONE, [], 'equal without base');
            }

            return new ReconciliationDecision(SyncAction::DIVERGED, $diff, 'differs but no base to attribute the change');
        }

        $localDiff = $base->diff($local);
        $remoteDiff = $base->diff($remote);

        if ([] === $localDiff && [] === $remoteDiff) {
            return new ReconciliationDecision(SyncAction::NONE, [], 'in sync');
        }

        if ([] === $remoteDiff) {
            return new ReconciliationDecision(SyncAction::PUSH, $localDiff, 'local changed since base');
        }

        if ([] === $localDiff) {
            return new ReconciliationDecision(SyncAction::PULL, $remoteDiff, 'remote changed since base');
        }

        if ($local->equals($remote)) {
            return new ReconciliationDecision(SyncAction::NONE, [], 'both sides changed identically; base is stale');
        }

        $overlap = array_values(array_intersect(
            array_column($localDiff, 'value'),
            array_column($remoteDiff, 'value'),
        ));

        if ([] !== $overlap) {
            $fields = array_values(array_map(WorklogField::from(...), $overlap));

            return new ReconciliationDecision(SyncAction::CONFLICT, $fields, 'both sides changed the same field(s)');
        }

        $union = [];
        foreach ([...$localDiff, ...$remoteDiff] as $field) {
            $union[$field->value] = $field;
        }

        return new ReconciliationDecision(SyncAction::MERGE, array_values($union), 'both dirty on disjoint fields');
    }
}
```

Note: `array_column($localDiff, 'value')` works on enum lists because backed enums expose `value`; the CONFLICT branch reports only the **overlapping** fields, MERGE reports the de-duplicated union in local-then-remote order.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/ReconciliationServiceTest.php`
Expected: PASS (13 data-provider cases).

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/ValueObject/Sync/ReconciliationDecision.php src/Service/Sync/ReconciliationService.php tests/Service/Sync/ReconciliationServiceTest.php
git commit -S --signoff -m "feat(sync): reconciliation decision matrix (ADR-023)"
```

---

### Task 7: Jira read methods on the legacy service

**Files:**
- Modify: `src/Service/Integration/Jira/JiraOAuthApiService.php` (append three public methods after `getSubtickets()`)
- Create: `src/DTO/Jira/JiraIssueKeySearchResult.php`
- Test: `tests/Service/Integration/Jira/JiraOAuthApiServiceReadTest.php`

**Interfaces:**
- Consumes: protected `$this->get(string $url): mixed` (`JiraOAuthApiService.php:559`) and public `searchTicket(string $jql, array $fields, int $limit = 1): mixed` (`:443`, overridden by `JiraCloudApiService` — so Cloud JQL works automatically).
- Produces: `getIssueWorklogs(string $issueKey): array` (list of `JiraWorkLog`); `searchIssueKeysWithWorklogs(string $jql, int $limit = 500): JiraIssueKeySearchResult`; `getMyself(): JiraUserIdentity`. `JiraIssueKeySearchResult` readonly: `(list<string> $keys, bool $truncated)`.

- [ ] **Step 1: Write the failing test**

Use the anonymous-subclass pattern already used in `tests/Service/Integration/Jira/JiraOAuthApiServiceTest.php:834` (a `new class(...) extends JiraOAuthApiService` overriding HTTP internals). Reuse that file's helper style for constructing `TokenEncryptionService` (see its `createTokenEncryptionService()` around line 63) — copy the helper, don't import the other test.

```php
namespace Tests\Service\Integration\Jira;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Routing\RouterInterface;

final class JiraOAuthApiServiceReadTest extends TestCase
{
    /**
     * @param array<string, mixed> $getResponses url => decoded response
     */
    private function serviceWithCannedResponses(array $getResponses, mixed $searchResponse = null): JiraOAuthApiService
    {
        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        // Match the construction used in JiraOAuthApiServiceTest::createTokenEncryptionService()
        // (a ParameterBag carrying a base64 32-byte APP_ENCRYPTION_KEY); adjust if that helper differs.
        $tokenEncryptionService = new TokenEncryptionService(new ParameterBag(['app_encryption_key' => base64_encode(str_repeat('k', 32))]));

        return new class($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService, $getResponses, $searchResponse) extends JiraOAuthApiService {
            /**
             * @param array<string, mixed> $getResponses
             */
            public function __construct(
                User $user,
                TicketSystem $ticketSystem,
                ManagerRegistry $managerRegistry,
                RouterInterface $router,
                TokenEncryptionService $tokenEncryptionService,
                private readonly array $getResponses,
                private readonly mixed $searchResponse,
            ) {
                parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
            }

            protected function get(string $url): mixed
            {
                return $this->getResponses[$url] ?? new \stdClass();
            }

            public function searchTicket(string $jql, array $fields, int $limit = 1): mixed
            {
                return $this->searchResponse;
            }
        };
    }

    public function testGetIssueWorklogsParsesWorklogArray(): void
    {
        $service = $this->serviceWithCannedResponses([
            'issue/ABC-1/worklog?maxResults=1000' => (object) [
                'worklogs' => [
                    (object) ['id' => '1', 'started' => '2026-07-08T09:00:00.000+0200', 'timeSpentSeconds' => 3600],
                    (object) ['id' => '2', 'started' => '2026-07-08T11:00:00.000+0200', 'timeSpentSeconds' => 1800],
                ],
            ],
        ]);

        $workLogs = $service->getIssueWorklogs('ABC-1');

        self::assertCount(2, $workLogs);
        self::assertContainsOnlyInstancesOf(JiraWorkLog::class, $workLogs);
        self::assertSame(1, $workLogs[0]->id);
    }

    public function testGetIssueWorklogsToleratesMalformedResponse(): void
    {
        $service = $this->serviceWithCannedResponses(['issue/ABC-1/worklog?maxResults=1000' => (object) ['unexpected' => true]]);

        self::assertSame([], $service->getIssueWorklogs('ABC-1'));
    }

    public function testSearchIssueKeysCollectsKeysAndDetectsTruncation(): void
    {
        $searchResponse = (object) [
            'total' => 700,
            'issues' => [(object) ['key' => 'ABC-1'], (object) ['key' => 'ABC-2']],
        ];

        $result = $this->serviceWithCannedResponses([], $searchResponse)->searchIssueKeysWithWorklogs('worklogAuthor = currentUser()', 500);

        self::assertSame(['ABC-1', 'ABC-2'], $result->keys);
        self::assertTrue($result->truncated);
    }

    public function testSearchIssueKeysNotTruncatedWhenComplete(): void
    {
        $searchResponse = (object) ['total' => 2, 'issues' => [(object) ['key' => 'ABC-1'], (object) ['key' => 'ABC-2']]];

        $result = $this->serviceWithCannedResponses([], $searchResponse)->searchIssueKeysWithWorklogs('any', 500);

        self::assertFalse($result->truncated);
    }

    public function testGetMyself(): void
    {
        $service = $this->serviceWithCannedResponses(['myself' => (object) ['accountId' => 'abc', 'name' => 'jdoe', 'emailAddress' => 'j@e.de']]);

        $identity = $service->getMyself();

        self::assertSame('abc', $identity->accountId);
        self::assertSame('jdoe', $identity->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Integration/Jira/JiraOAuthApiServiceReadTest.php`
Expected: FAIL — undefined methods `getIssueWorklogs` / `searchIssueKeysWithWorklogs` / `getMyself`. (If the `TokenEncryptionService` construction errors instead, copy the exact helper from `JiraOAuthApiServiceTest` — the intent is a working instance, not a specific parameter name.)

- [ ] **Step 3: Implement**

`src/DTO/Jira/JiraIssueKeySearchResult.php`:
```php
namespace App\DTO\Jira;

/**
 * Issue keys from a JQL search, with an explicit truncation flag — no silent caps (ADR-023).
 */
final readonly class JiraIssueKeySearchResult
{
    /**
     * @param list<string> $keys
     */
    public function __construct(
        public array $keys,
        public bool $truncated,
    ) {
    }
}
```

Append to `src/Service/Integration/Jira/JiraOAuthApiService.php` (after `getSubtickets()`; add `use App\DTO\Jira\JiraIssueKeySearchResult;`, `use App\DTO\Jira\JiraUserIdentity;`, `use App\DTO\Jira\JiraWorkLog;` — check which are already imported):

```php
/**
 * Reads all worklogs of one issue (ADR-023 read path 3).
 *
 * @return list<JiraWorkLog>
 *
 * @throws JiraApiException
 */
public function getIssueWorklogs(string $issueKey): array
{
    $response = $this->get(sprintf('issue/%s/worklog?maxResults=1000', $issueKey));

    if (!is_object($response) || !isset($response->worklogs) || !is_array($response->worklogs)) {
        return [];
    }

    $workLogs = [];
    foreach ($response->worklogs as $workLog) {
        if (is_object($workLog)) {
            $workLogs[] = JiraWorkLog::fromApiResponse($workLog);
        }
    }

    return $workLogs;
}

/**
 * JQL search returning issue keys only, with explicit truncation reporting (ADR-023 read path 2).
 *
 * @throws JiraApiException
 */
public function searchIssueKeysWithWorklogs(string $jql, int $limit = 500): JiraIssueKeySearchResult
{
    $response = $this->searchTicket($jql, ['key'], $limit);

    $keys = [];
    if (is_object($response) && isset($response->issues) && is_array($response->issues)) {
        foreach ($response->issues as $issue) {
            if (is_object($issue) && isset($issue->key) && is_string($issue->key)) {
                $keys[] = $issue->key;
            }
        }
    }

    $total = is_object($response) && isset($response->total) && is_numeric($response->total) ? (int) $response->total : null;
    $truncated = count($keys) >= $limit || (null !== $total && $total > count($keys));

    return new JiraIssueKeySearchResult($keys, $truncated);
}

/**
 * The Jira account behind the current token (GET myself) — for author filtering.
 *
 * @throws JiraApiException
 */
public function getMyself(): JiraUserIdentity
{
    $response = $this->get('myself');

    return JiraUserIdentity::fromApiResponse(is_object($response) ? $response : new stdClass());
}
```

(`stdClass` needs a `use stdClass;` import; `is_array`/`is_object`/`is_string`/`is_numeric`/`count`/`sprintf` function imports per file conventions — mirror the existing `use function` block in the file.)

- [ ] **Step 4: Run tests to verify they pass, including all pre-existing legacy service tests**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Integration/Jira`
Expected: PASS — new file green, zero regressions in `JiraOAuthApiServiceTest`, `JiraCloudApiServiceTest`.

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Integration/Jira/JiraOAuthApiService.php src/DTO/Jira/JiraIssueKeySearchResult.php tests/Service/Integration/Jira/JiraOAuthApiServiceReadTest.php
git commit -S --signoff -m "feat(jira): worklog read methods on legacy API service (ADR-023 read paths)"
```

---

### Task 8: EntryRepository sync-candidate query

**Files:**
- Modify: `src/Repository/EntryRepository.php` (append method after `findByUserAndTicketSystemToSync`, `:1203`)
- Test: `tests/Repository/EntryRepositorySyncCandidatesTest.php` (integration suite — `tests/Repository` is DB-backed)

**Interfaces:**
- Consumes: `Entry`, `User`, `TicketSystem` entities.
- Produces: `findJiraSyncCandidates(User $user, TicketSystem $ticketSystem, DateTimeInterface $from, DateTimeInterface $to): array` returning `list<Entry>` — the user's entries in the day range whose project uses the ticket system, with non-empty ticket, excluding internal-mirror remaps (`internalJiraTicketOriginalKey IS NULL`, same exclusion as `findByUserAndTicketSystemToSync` — ADR-023 defers the mirror interaction).

- [ ] **Step 1: Write the failing integration test**

Model the fixture handling on the existing `tests/Repository/EntryRepositoryIntegrationTest.php` (same base class and DB bootstrapping — read it first and reuse its setup idiom; the test DB is seeded via the same mechanism all `tests/Repository` integration tests use):

```php
namespace Tests\Repository;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Repository\EntryRepository;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntryRepositorySyncCandidatesTest extends KernelTestCase
{
    private EntryRepository $entryRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $repository);
        $this->entryRepository = $repository;
    }

    public function testFindJiraSyncCandidatesFiltersByUserSystemRangeAndTicket(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        /** @var User $user */
        $user = $entityManager->getRepository(User::class)->findOneBy([]) ?? self::fail('fixture user missing');
        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $entityManager->getRepository(TicketSystem::class)->findOneBy([]) ?? self::fail('fixture ticket system missing');
        /** @var Project $project */
        $project = $entityManager->getRepository(Project::class)->findOneBy([]) ?? self::fail('fixture project missing');
        $project->setTicketSystem($ticketSystem);

        $inRange = (new Entry())
            ->setUser($user)->setProject($project)->setTicket('ABC-1')
            ->setDay(new DateTime('2026-06-15'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00'));
        $noTicket = (new Entry())
            ->setUser($user)->setProject($project)->setTicket('')
            ->setDay(new DateTime('2026-06-15'))->setStart(new DateTime('10:00'))->setEnd(new DateTime('11:00'));
        $outOfRange = (new Entry())
            ->setUser($user)->setProject($project)->setTicket('ABC-2')
            ->setDay(new DateTime('2026-07-15'))->setStart(new DateTime('09:00'))->setEnd(new DateTime('10:00'));

        $entityManager->persist($inRange);
        $entityManager->persist($noTicket);
        $entityManager->persist($outOfRange);
        $entityManager->flush();

        $result = $this->entryRepository->findJiraSyncCandidates($user, $ticketSystem, new DateTime('2026-06-01'), new DateTime('2026-06-30'));

        $ids = array_map(static fn (Entry $entry): ?int => $entry->getId(), $result);
        self::assertContains($inRange->getId(), $ids);
        self::assertNotContains($noTicket->getId(), $ids);
        self::assertNotContains($outOfRange->getId(), $ids);
    }
}
```

If `Entry`'s fluent setters differ in name (check `src/Entity/Entry.php` setters before writing), adjust the fixture construction — the assertions are the contract.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Repository/EntryRepositorySyncCandidatesTest.php`
Expected: FAIL — `Call to undefined method ... findJiraSyncCandidates()`.
(Integration tests need the test DB; if the run fails on DATABASE_URL, follow the repo's documented test-db setup — beware `.env.test.local` overriding it; export `DATABASE_URL` as a real env var if needed.)

- [ ] **Step 3: Implement the repository method**

Append to `src/Repository/EntryRepository.php` (mirror the style of `findByUserAndTicketSystemToSync` directly above):

```php
/**
 * Entries eligible for worklog verification/sync (ADR-023): the user's entries in the
 * day range whose project books on the given ticket system, with a ticket set.
 * Internal-mirror remaps are excluded (interaction deferred by ADR-023).
 *
 * @return list<Entry>
 */
public function findJiraSyncCandidates(User $user, TicketSystem $ticketSystem, DateTimeInterface $from, DateTimeInterface $to): array
{
    /** @var list<Entry> */
    return $this->createQueryBuilder('e')
        ->join('e.project', 'p')
        ->where('e.user = :user')
        ->andWhere('p.ticketSystem = :ticketSystem')
        ->andWhere('e.day >= :fromDay')
        ->andWhere('e.day <= :toDay')
        ->andWhere("e.ticket != ''")
        ->andWhere('e.internalJiraTicketOriginalKey IS NULL')
        ->setParameter('user', $user)
        ->setParameter('ticketSystem', $ticketSystem)
        ->setParameter('fromDay', $from->format('Y-m-d'))
        ->setParameter('toDay', $to->format('Y-m-d'))
        ->orderBy('e.day', 'ASC')
        ->addOrderBy('e.start', 'ASC')
        ->getQuery()
        ->getResult();
}
```

Add `use DateTimeInterface;` if missing. If the existing `findByUserAndTicketSystemToSync` expresses the internal-mirror exclusion differently (e.g. checks empty string too), replicate ITS condition verbatim.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Repository/EntryRepositorySyncCandidatesTest.php`
Expected: PASS.

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer cs-fix
git add src/Repository/EntryRepository.php tests/Repository/EntryRepositorySyncCandidatesTest.php
git commit -S --signoff -m "feat(sync): entry repository query for worklog sync candidates (ADR-023)"
```

---

### Task 9: VerifyWorklogsService — the dry-run engine

**Files:**
- Create: `src/Service/Sync/VerifyWorklogsService.php`
- Test: `tests/Service/Sync/VerifyWorklogsServiceTest.php`

**Interfaces:**
- Consumes: everything above — `EntryRepository::findJiraSyncCandidates(...)` (Task 8), `WorklogSyncStateRepository::findByEntryIds(...)` (Task 2), `JiraOAuthApiFactory::create(User, TicketSystem): JiraOAuthApiService` (existing), the three read methods (Task 7), `EntryWorklogProjector::project`, `RemoteWorklogNormalizer::normalize`, `ReconciliationService::reconcile`, `WorklogSnapshot::fromArray`, entities/enums.
- Produces: `verify(User $user, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to): SyncRun` — persisted, `COMPLETED` (or `FAILED` on error, rethrown). Counter keys: `in_sync`, `local_dirty`, `remote_dirty`, `mergeable`, `conflicts`, `diverged`, `local_only`, `remote_only`, `never_synced`, `errors`. **Writes nothing except `sync_runs` + `sync_run_items`** — no entry writes, no `worklog_sync_state` writes, no Jira writes.

- [ ] **Step 1: Write the failing test**

```php
namespace Tests\Service\Sync;

use App\DTO\Jira\JiraIssueKeySearchResult;
use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Repository\EntryRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use App\Service\Sync\EntryWorklogProjector;
use App\Service\Sync\ReconciliationService;
use App\Service\Sync\RemoteWorklogNormalizer;
use App\Service\Sync\VerifyWorklogsService;
use App\Service\Sync\WorklogCommentCodec;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(VerifyWorklogsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class VerifyWorklogsServiceTest extends TestCase
{
    private EntryRepository&MockObject $entryRepository;
    private WorklogSyncStateRepository&MockObject $syncStateRepository;
    private JiraOAuthApiFactory&MockObject $apiFactory;
    private JiraOAuthApiService&MockObject $api;
    private EntityManagerInterface&MockObject $entityManager;
    private VerifyWorklogsService $service;
    private User $user;
    private TicketSystem $ticketSystem;

    protected function setUp(): void
    {
        $this->entryRepository = $this->createMock(EntryRepository::class);
        $this->syncStateRepository = $this->createMock(WorklogSyncStateRepository::class);
        $this->apiFactory = $this->createMock(JiraOAuthApiFactory::class);
        $this->api = $this->createMock(JiraOAuthApiService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->apiFactory->method('create')->willReturn($this->api);
        $this->user = self::createStub(User::class);
        $this->ticketSystem = self::createStub(TicketSystem::class);

        $this->service = new VerifyWorklogsService(
            $this->entityManager,
            $this->entryRepository,
            $this->syncStateRepository,
            $this->apiFactory,
            new EntryWorklogProjector(new WorklogCommentCodec()),
            new RemoteWorklogNormalizer(),
            new ReconciliationService(),
            new MockClock('2026-07-09 12:00:00'),
        );
    }

    /**
     * Entry #42, Development, "fixed it", 2026-06-15 09:00, 60 min, linked worklog 1001.
     */
    private function linkedEntry(): Entry
    {
        $activity = self::createStub(Activity::class);
        $activity->method('getName')->willReturn('Development');

        $entry = self::createStub(Entry::class);
        $entry->method('getId')->willReturn(42);
        $entry->method('getTicket')->willReturn('ABC-1');
        $entry->method('getWorklogId')->willReturn(1001);
        $entry->method('getDay')->willReturn(new DateTime('2026-06-15'));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 09:00:00'));
        $entry->method('getDuration')->willReturn(60);
        $entry->method('getDescription')->willReturn('fixed it');
        $entry->method('getActivity')->willReturn($activity);

        return $entry;
    }

    /**
     * A remote worklog that exactly matches linkedEntry()'s projection.
     */
    private function matchingRemote(): JiraWorkLog
    {
        return new JiraWorkLog(
            id: 1001,
            comment: '#42: Development: fixed it',
            started: (new DateTime('2026-06-15 09:00:00'))->format('Y-m-d\TH:i:s.000O'),
            timeSpentSeconds: 3600,
            updated: '2026-06-15T10:00:00.000+0200',
            authorAccountId: 'me',
        );
    }

    private function stubJira(array $issueKeys, array $worklogsByIssue): void
    {
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'me'));
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult($issueKeys, false));
        $this->api->method('getIssueWorklogs')->willReturnCallback(
            static fn (string $key): array => $worklogsByIssue[$key] ?? [],
        );
    }

    private function verify(): \App\Entity\SyncRun
    {
        return $this->service->verify($this->user, $this->ticketSystem, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'));
    }

    public function testMatchingPairWithoutBaseCountsInSync(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$this->linkedEntry()]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira(['ABC-1'], ['ABC-1' => [$this->matchingRemote()]]);

        $syncRun = $this->verify();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['in_sync'] ?? 0);
        self::assertCount(0, $syncRun->getItems());
    }

    public function testUnmatchedRemoteWorklogBecomesRemoteOnlyItem(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $remote = new JiraWorkLog(id: 2002, comment: 'jira-side work', started: '2026-06-10T14:00:00.000+0200', timeSpentSeconds: 1800, authorAccountId: 'me');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$remote]]);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['remote_only'] ?? 0);
        $items = $syncRun->getItems()->toArray();
        self::assertCount(1, $items);
        self::assertSame(SyncItemKind::REMOTE_ONLY, $items[0]->getKind());
        self::assertSame('ABC-9', $items[0]->getIssueKey());
        self::assertSame(2002, $items[0]->getRemoteWorklogId());
    }

    public function testForeignAuthorWorklogsAreIgnored(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $foreign = new JiraWorkLog(id: 3003, started: '2026-06-10T14:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'someone-else');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$foreign]]);

        $syncRun = $this->verify();

        self::assertSame(0, $syncRun->getCounters()['remote_only'] ?? 0);
        self::assertCount(0, $syncRun->getItems());
    }

    public function testWorklogOutsideDateRangeIsIgnored(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $outside = new JiraWorkLog(id: 4004, started: '2026-05-31T14:00:00.000+0200', timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$outside]]);

        $syncRun = $this->verify();

        self::assertCount(0, $syncRun->getItems());
    }

    public function testLinkedEntryWithMissingRemoteCountsLocalOnly(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$this->linkedEntry()]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira([], []);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['local_only'] ?? 0);
        self::assertSame(SyncItemKind::LOCAL_ONLY, $syncRun->getItems()->toArray()[0]->getKind());
    }

    public function testUnlinkedEntryCountsNeverSynced(): void
    {
        $entry = self::createStub(Entry::class);
        $entry->method('getId')->willReturn(43);
        $entry->method('getTicket')->willReturn('ABC-2');
        $entry->method('getWorklogId')->willReturn(null);
        $entry->method('getDay')->willReturn(new DateTime('2026-06-16'));
        $entry->method('getStart')->willReturn(new DateTime('1970-01-01 10:00:00'));
        $entry->method('getDuration')->willReturn(30);
        $entry->method('getDescription')->willReturn('x');
        $entry->method('getActivity')->willReturn(null);

        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$entry]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira([], []);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['never_synced'] ?? 0);
    }

    public function testDivergedPairProducesDivergedItemWithFieldPayload(): void
    {
        $entry = $this->linkedEntry();
        $remote = new JiraWorkLog(
            id: 1001,
            comment: '#42: Development: fixed it',
            started: (new DateTime('2026-06-15 09:00:00'))->format('Y-m-d\TH:i:s.000O'),
            timeSpentSeconds: 7200, // 120 min vs local 60 min
            authorAccountId: 'me',
        );
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([$entry]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->stubJira(['ABC-1'], ['ABC-1' => [$remote]]);

        $syncRun = $this->verify();

        self::assertSame(1, $syncRun->getCounters()['diverged'] ?? 0);
        $item = $syncRun->getItems()->toArray()[0];
        self::assertSame(SyncItemKind::DIVERGED, $item->getKind());
        self::assertSame(['duration'], $item->getPayload()['fields'] ?? null);
    }

    public function testTruncatedSearchIsReportedAsItem(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $this->api->method('getMyself')->willReturn(new JiraUserIdentity(accountId: 'me'));
        $this->api->method('searchIssueKeysWithWorklogs')->willReturn(new JiraIssueKeySearchResult(['ABC-1'], true));
        $this->api->method('getIssueWorklogs')->willReturn([]);

        $syncRun = $this->verify();

        $kinds = array_map(static fn ($item) => $item->getKind(), $syncRun->getItems()->toArray());
        self::assertContains(SyncItemKind::TRUNCATED, $kinds);
    }

    public function testUnparseableRemoteWorklogBecomesErrorItemAndRunContinues(): void
    {
        $this->entryRepository->method('findJiraSyncCandidates')->willReturn([]);
        $this->syncStateRepository->method('findByEntryIds')->willReturn([]);
        $broken = new JiraWorkLog(id: 5005, started: null, timeSpentSeconds: 600, authorAccountId: 'me');
        $this->stubJira(['ABC-9'], ['ABC-9' => [$broken]]);

        $syncRun = $this->verify();

        self::assertSame(SyncRunStatus::COMPLETED, $syncRun->getStatus());
        self::assertSame(1, $syncRun->getCounters()['errors'] ?? 0);
        self::assertSame(SyncItemKind::ERROR, $syncRun->getItems()->toArray()[0]->getKind());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/VerifyWorklogsServiceTest.php`
Expected: FAIL — `VerifyWorklogsService` not found.

- [ ] **Step 3: Implement**

`src/Service/Sync/VerifyWorklogsService.php`:
```php
namespace App\Service\Sync;

use App\Entity\Entry;
use App\Entity\SyncRun;
use App\Entity\SyncRunItem;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncAction;
use App\Enum\SyncItemKind;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Repository\EntryRepository;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\ValueObject\Sync\WorklogSnapshot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_map;
use function sprintf;

/**
 * ADR-023 verify: the reconciliation engine with all writes disabled. Reads TT and Jira,
 * writes ONLY a SyncRun report — never entries, never sync state, never Jira.
 */
class VerifyWorklogsService
{
    /** @var array<string, string> SyncAction value => counter key */
    private const array ACTION_COUNTERS = [
        'none' => 'in_sync',
        'push' => 'local_dirty',
        'pull' => 'remote_dirty',
        'merge' => 'mergeable',
        'conflict' => 'conflicts',
        'diverged' => 'diverged',
        'remote_missing' => 'local_only',
    ];

    /** @var array<string, SyncItemKind> SyncAction value => item kind (actions that yield items) */
    private const array ACTION_ITEM_KINDS = [
        'push' => SyncItemKind::LOCAL_DIRTY,
        'pull' => SyncItemKind::REMOTE_DIRTY,
        'merge' => SyncItemKind::MERGEABLE,
        'conflict' => SyncItemKind::CONFLICT,
        'diverged' => SyncItemKind::DIVERGED,
        'remote_missing' => SyncItemKind::LOCAL_ONLY,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entryRepository,
        private readonly WorklogSyncStateRepository $worklogSyncStateRepository,
        private readonly JiraOAuthApiFactory $jiraOAuthApiFactory,
        private readonly EntryWorklogProjector $entryWorklogProjector,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly ReconciliationService $reconciliationService,
        private readonly ClockInterface $clock,
    ) {
    }

    public function verify(User $user, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to): SyncRun
    {
        $syncRun = (new SyncRun())
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::RUNNING)
            ->setTicketSystem($ticketSystem)
            ->setTriggeredBy($user)
            ->setScope(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'dry_run' => true])
            ->setCounters([])
            ->setStartedAt(DateTimeImmutable::createFromInterface($this->clock->now()));

        $this->entityManager->persist($syncRun);

        try {
            $this->run($syncRun, $user, $ticketSystem, $from, $to);
            $syncRun->setStatus(SyncRunStatus::COMPLETED);
        } catch (Throwable $throwable) {
            $syncRun->setStatus(SyncRunStatus::FAILED);
            $this->addItem($syncRun, SyncItemKind::ERROR, reason: substr($throwable->getMessage(), 0, 255));
        }

        $syncRun->setFinishedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        return $syncRun;
    }

    private function run(SyncRun $syncRun, User $user, TicketSystem $ticketSystem, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $api = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
        $myself = $api->getMyself();

        // --- Remote side: worklogs authored by this user in range, keyed by worklog id.
        $jql = sprintf(
            'worklogAuthor = currentUser() AND worklogDate >= "%s" AND worklogDate <= "%s"',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );
        $searchResult = $api->searchIssueKeysWithWorklogs($jql);
        if ($searchResult->truncated) {
            $this->addItem($syncRun, SyncItemKind::TRUNCATED, reason: 'issue search hit its result cap; report may be incomplete');
        }

        $rangeFrom = $from->setTime(0, 0)->getTimestamp();
        $rangeTo = $to->setTime(23, 59, 59)->getTimestamp();

        /** @var array<int, array{snapshot: WorklogSnapshot, updated: ?string, author: ?string}> $remoteByWorklogId */
        $remoteByWorklogId = [];
        foreach ($searchResult->keys as $issueKey) {
            foreach ($api->getIssueWorklogs($issueKey) as $jiraWorkLog) {
                if (null === $jiraWorkLog->id || !$myself->matchesWorklogAuthor($jiraWorkLog)) {
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

                $remoteByWorklogId[$jiraWorkLog->id] = [
                    'snapshot' => $snapshot,
                    'updated' => $jiraWorkLog->updated,
                    'author' => $jiraWorkLog->authorAccountId ?? $jiraWorkLog->authorName,
                ];
            }
        }

        // --- Local side.
        $entries = $this->entryRepository->findJiraSyncCandidates($user, $ticketSystem, $from, $to);
        $entryIds = array_map(static fn (Entry $entry): int => (int) $entry->getId(), $entries);
        $syncStates = $this->worklogSyncStateRepository->findByEntryIds($entryIds);

        foreach ($entries as $entry) {
            $worklogId = $entry->getWorklogId();
            if (null === $worklogId || $worklogId <= 0) {
                $syncRun->incrementCounter('never_synced');
                $this->addItem($syncRun, SyncItemKind::NEVER_SYNCED, issueKey: $entry->getTicket(), entry: $entry, reason: 'entry has no linked Jira worklog');
                continue;
            }

            $base = null;
            $syncState = $syncStates[(int) $entry->getId()] ?? null;
            if (null !== $syncState) {
                $base = WorklogSnapshot::fromArray($syncState->getBasePayload());
            }

            $local = $this->entryWorklogProjector->project($entry);
            $remote = null;
            if (isset($remoteByWorklogId[$worklogId])) {
                $remote = $remoteByWorklogId[$worklogId]['snapshot'];
                unset($remoteByWorklogId[$worklogId]);
            }

            $decision = $this->reconciliationService->reconcile($base, $local, $remote);
            $syncRun->incrementCounter(self::ACTION_COUNTERS[$decision->action->value] ?? 'errors');

            $itemKind = self::ACTION_ITEM_KINDS[$decision->action->value] ?? null;
            if ($itemKind instanceof SyncItemKind) {
                $this->addItem(
                    $syncRun,
                    $itemKind,
                    issueKey: $entry->getTicket(),
                    remoteWorklogId: $worklogId,
                    entry: $entry,
                    reason: $decision->reason,
                    payload: [
                        'fields' => array_map(static fn ($field) => $field->value, $decision->fields),
                        'local' => $local->toArray(),
                        'remote' => $remote?->toArray(),
                    ],
                );
            }
        }

        // --- Whatever remains on the remote side has no matching entry.
        foreach ($remoteByWorklogId as $worklogId => $remoteData) {
            $syncRun->incrementCounter('remote_only');
            $this->addItem(
                $syncRun,
                SyncItemKind::REMOTE_ONLY,
                issueKey: $remoteData['snapshot']->issueKey,
                remoteWorklogId: $worklogId,
                author: $remoteData['author'],
                reason: 'Jira worklog has no matching entry (import candidate)',
                payload: ['remote' => $remoteData['snapshot']->toArray(), 'updated' => $remoteData['updated']],
            );
        }
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
            (new SyncRunItem())
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

Note: PHPStan level 10 will demand `substr()` never sees non-string — `Throwable::getMessage()` is `string`, fine. If `JiraOAuthApiFactory` is final, `createMock` still works (Symfony's bypass-finals is not in play — check; if mocking fails, extract-and-mock is NOT the fix: mock `JiraOAuthApiService` and stub the factory via a real instance is impossible, so instead mark the factory non-final or create a tiny interface — prefer asking the reviewer; as of the research pass the class is not final).

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/VerifyWorklogsServiceTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze
docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add src/Service/Sync/VerifyWorklogsService.php tests/Service/Sync/VerifyWorklogsServiceTest.php
git commit -S --signoff -m "feat(sync): verify service - reconciliation engine with writes disabled (ADR-023)"
```

---

### Task 10: `tt:verify-worklogs` console command + ADR update

**Files:**
- Create: `src/Command/TtVerifyWorklogsCommand.php`
- Test: `tests/Command/TtVerifyWorklogsCommandTest.php`
- Modify: `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md` (verification-points section)

**Interfaces:**
- Consumes: `VerifyWorklogsService::verify(User, TicketSystem, DateTimeImmutable, DateTimeImmutable): SyncRun` (Task 9).
- Produces: command `tt:verify-worklogs <username> <ticket-system-id> [--from=Y-m-d] [--to=Y-m-d]`; exit 0 on success, 1 on unknown user/ticket-system or failed run.

- [ ] **Step 1: Write the failing test**

```php
namespace Tests\Command;

use App\Command\TtVerifyWorklogsCommand;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Enum\SyncRunType;
use App\Service\Sync\VerifyWorklogsService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TtVerifyWorklogsCommandTest extends TestCase
{
    private function commandTester(?User $user, ?TicketSystem $ticketSystem, ?SyncRun $syncRun = null): CommandTester
    {
        $userRepository = $this->createMock(ObjectRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);
        $ticketSystemRepository = $this->createMock(ObjectRepository::class);
        $ticketSystemRepository->method('find')->willReturn($ticketSystem);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')->willReturnMap([
            [User::class, null, $userRepository],
            [TicketSystem::class, null, $ticketSystemRepository],
        ]);

        $verifyService = $this->createMock(VerifyWorklogsService::class);
        if (null !== $syncRun) {
            $verifyService->method('verify')->willReturn($syncRun);
        }

        return new CommandTester(new TtVerifyWorklogsCommand($verifyService, $managerRegistry));
    }

    private function completedRun(): SyncRun
    {
        return (new SyncRun())
            ->setType(SyncRunType::VERIFY)
            ->setStatus(SyncRunStatus::COMPLETED)
            ->setCounters(['in_sync' => 3, 'remote_only' => 1])
            ->setStartedAt(new DateTimeImmutable('2026-07-09 12:00:00'))
            ->setFinishedAt(new DateTimeImmutable('2026-07-09 12:00:05'));
    }

    public function testRunsAndPrintsCounters(): void
    {
        $tester = $this->commandTester(self::createStub(User::class), self::createStub(TicketSystem::class), $this->completedRun());

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '1', '--from' => '2026-06-01', '--to' => '2026-06-30']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('in_sync', $tester->getDisplay());
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->commandTester(null, self::createStub(TicketSystem::class));

        $exitCode = $tester->execute(['username' => 'ghost', 'ticket-system' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    public function testUnknownTicketSystemFails(): void
    {
        $tester = $this->commandTester(self::createStub(User::class), null);

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '99']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Ticket system not found', $tester->getDisplay());
    }

    public function testFailedRunExitsNonZero(): void
    {
        $failedRun = $this->completedRun()->setStatus(SyncRunStatus::FAILED);
        $tester = $this->commandTester(self::createStub(User::class), self::createStub(TicketSystem::class), $failedRun);

        $exitCode = $tester->execute(['username' => 'jdoe', 'ticket-system' => '1']);

        self::assertSame(1, $exitCode);
    }
}
```

If `ManagerRegistry::getRepository`'s second parameter default differs (persistence-bundle version), adjust the `willReturnMap` rows to match the actual signature — or switch to `willReturnCallback` keyed on the class name, which is version-proof.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Command/TtVerifyWorklogsCommandTest.php`
Expected: FAIL — command class not found.

- [ ] **Step 3: Implement the command**

`src/Command/TtVerifyWorklogsCommand.php` (invokable style like `TtSyncSubticketsCommand`):
```php
namespace App\Command;

use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\SyncRunStatus;
use App\Service\Sync\VerifyWorklogsService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tt:verify-worklogs', description: 'Compare TimeTracker entries with Jira worklogs (read-only, ADR-023)')]
class TtVerifyWorklogsCommand extends Command
{
    public function __construct(
        private readonly VerifyWorklogsService $verifyWorklogsService,
        private readonly ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    public function __invoke(
        #[Argument(description: 'TimeTracker username', name: 'username')]
        string $username,
        #[Argument(description: 'Ticket system ID', name: 'ticket-system')]
        string $ticketSystem,
        #[Option(description: 'Start date (Y-m-d); default: first day of current month', name: 'from')]
        ?string $from,
        #[Option(description: 'End date (Y-m-d); default: today', name: 'to')]
        ?string $to,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

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

        $fromDate = null !== $from ? new DateTimeImmutable($from) : new DateTimeImmutable('first day of this month');
        $toDate = null !== $to ? new DateTimeImmutable($to) : new DateTimeImmutable('today');

        $syncRun = $this->verifyWorklogsService->verify($user, $system, $fromDate, $toDate);

        $this->render($symfonyStyle, $syncRun);

        return SyncRunStatus::COMPLETED === $syncRun->getStatus() ? Command::SUCCESS : 1;
    }

    private function render(SymfonyStyle $symfonyStyle, SyncRun $syncRun): void
    {
        $symfonyStyle->section(sprintf(
            'Verify run #%d — %s (%s to %s)',
            $syncRun->getId() ?? 0,
            $syncRun->getStatus()->value,
            (string) ($syncRun->getScope()['from'] ?? '?'),
            (string) ($syncRun->getScope()['to'] ?? '?'),
        ));

        $rows = [];
        foreach ($syncRun->getCounters() as $key => $count) {
            $rows[] = [$key, $count];
        }

        $symfonyStyle->table(['result', 'count'], $rows);

        foreach ($syncRun->getItems() as $item) {
            $symfonyStyle->writeln(sprintf(
                ' <comment>%-18s</comment> %s %s %s',
                $item->getKind()->value,
                $item->getIssueKey() ?? '-',
                null !== $item->getRemoteWorklogId() ? '(worklog ' . $item->getRemoteWorklogId() . ')' : '',
                $item->getReason(),
            ));
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Command/TtVerifyWorklogsCommandTest.php`
Expected: PASS (4 tests). Also smoke the registration: `docker compose --profile dev exec app-dev php bin/console list tt` — `tt:verify-worklogs` appears.

- [ ] **Step 5: Update ADR-023 verification points**

In `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md`, replace the "Verification points before implementation" list entries 1 and 3 with resolved findings:

```markdown
1. ~~Exact comment/description projection the current push writes to Jira~~ **Resolved (Phase 1):** `#<entryId>: <activityName>: <description>` with fallbacks `no activity specified` / `no description given` (`JiraOAuthApiService::getTicketSystemWorkLogComment`). `WorklogCommentCodec` reproduces it; the entry ID embedded in every pushed comment is a secondary identity anchor.
```

```markdown
3. ~~Container wiring status of the refactored stack~~ **Resolved (Phase 1), decision amended:** the refactored stack is container-excluded AND writes a different comment format (`Customer | Project | Activity | description`) than production. Phase 1 therefore puts read methods on the legacy `JiraOAuthApiService` (wired, dual Server/Cloud via `JiraCloudApiService`) instead of wiring the refactored stack. Revisit when the lease-checked write service lands (Phase 3).
```

- [ ] **Step 6: Full gates, final commit**

```bash
docker compose --profile dev exec app-dev composer check:all
docker compose --profile dev exec app-dev composer test:fast
git add src/Command/TtVerifyWorklogsCommand.php tests/Command/TtVerifyWorklogsCommandTest.php docs/adr/ADR-023-jira-worklog-bidirectional-sync.md
git commit -S --signoff -m "feat(sync): tt:verify-worklogs read-only verification command (ADR-023)"
```

---

## Phase boundaries (for orientation only — NOT part of this plan)

- **Phase 2 (separate plan):** import — `remote_account_id` on `users_ticket_systems`, shadow users, ticket→project resolution parking, probable-duplicate heuristic, entry creation with push suppression, chunked/resumable runs.
- **Phase 3 (separate plan):** lease-checked `WorklogWriteService`, `EntryEventSubscriber` upgrade, pull/merge/delete/move, per-ticket-system cursor + designated sync user, `tt:sync-worklogs` cron.
- **Phase 4 (separate plan):** v2 endpoints (`/api/v2/worklog-sync/*`), PAT scopes, MCP tools, SPA UI.
