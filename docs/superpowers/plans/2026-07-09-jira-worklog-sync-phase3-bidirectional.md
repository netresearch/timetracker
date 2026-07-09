# Jira Worklog Sync — Phase 3: Bidirectional Sync (Lease Writes, Pull/Merge, Cursor Cron) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute the ADR-023 reconciliation matrix with real writes: lease-checked (CAS) pushes everywhere (including the UI save path), pulls/merges of Jira-side edits into TT, delete/move handling, and an incremental `tt:sync-worklogs` cron driven by Jira's `worklog/updated` cursor (ADR-023 use case 2).

**Architecture:** Per [ADR-023](../../adr/ADR-023-jira-worklog-bidirectional-sync.md) §1/§2/§5. `WorklogWriteService` wraps the existing legacy write (`JiraOAuthApiService::updateEntryJiraWorkLog` — which already handles zero-duration deletes, guards, and id storage) with the lease protocol: GET worklog → compare `updated` against `WorklogSyncState.baseUpdatedAt` → write → GET again → refresh base. `EntryEventSubscriber.bookWorklog` delegates to it, converting today's silent clobber into parked conflicts. `SyncWorklogsService` (an `AbstractSyncRunService`) consumes the `worklog/updated` + `worklog/deleted` feeds, executes the matrix (push/pull/merge/conflict/orphan/move-relink), optionally auto-imports unmatched worklogs when the ticket system is configured for it, and advances a per-ticket-system cursor only on completed non-dry runs.

**Two policy decisions this plan encodes** (record both in the ADR, Task 9):
1. **Base bootstrapping:** entries pushed before ADR-023 have no `WorklogSyncState`. A lease write without a base performs the write and *seeds* the base afterwards (one-time transition per entry) instead of parking — otherwise every legacy entry's first edit would park.
2. **Unattended import config:** cron sync auto-imports unmatched remote worklogs only when the ticket system has both a **sync user** and a **default import activity** configured; otherwise they surface as `REMOTE_ONLY` items.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 + Migrations, PHPUnit 13, PHPStan level 10, Rector.

## Global Constraints

- License header + `declare(strict_types=1);` in every new PHP file (same block as Phases 1–2 — copy from any `src/Service/Sync/*.php`).
- All commands in the container: `docker compose --profile dev exec app-dev <cmd>`.
- Gates before EVERY commit, all four tools: `composer analyze`, `composer analyze:arch`, `composer rector` (apply, re-run cs-fix + tests after), `composer cs-fix`.
- Commits conventional, `git commit -S --signoff`, no AI attribution. NEVER stage `config/reference.php` (pre-existing unrelated modification).
- Unit tests: no DB, `self::assertSame`, under `tests/Service/...`; `tests/Command`/`tests/Repository` are integration. Repo cs rules produce `new X()->…` (parens-less); accept.
- 404 from Jira = `JiraApiInvalidResourceException` (`App\Exception\Integration\Jira\...`); 401 redirects; other errors = `JiraApiException`.
- Writes NEVER dispatch `EntryEvent` from sync services (echo-back). The subscriber path is the only event consumer and it must keep every existing behavioral guarantee in `tests/EventSubscriber/EntryEventSubscriberTest.php` except the ones this plan explicitly changes (blind write → lease write).
- `sql/full.sql` is the tracked source of the generated test schema — mirror every migration change there (column + index), as Phase 2 did.

---

### Task 1: Schema — sync user, default import activity, cursor

**Files:**
- Create: `migrations/Version20260709_TicketSystemSyncConfig.php`
- Modify: `src/Entity/TicketSystem.php` (three new fields + accessors)
- Modify: `sql/full.sql` (mirror columns in `ticket_systems` CREATE TABLE)
- Test: `tests/Entity/TicketSystemTest.php` (extend — file exists and is in the unit suite)

**Interfaces:**
- Produces: `TicketSystem::getSyncUser(): ?User` / `setSyncUser(?User): static`; `getSyncDefaultActivity(): ?Activity` / `setSyncDefaultActivity(?Activity): static`; `getWorklogSyncCursor(): ?int` / `setWorklogSyncCursor(?int): static` (epoch **milliseconds**, raw from Jira's `until`).

- [ ] **Step 1: Extend the failing entity test**

Append to `tests/Entity/TicketSystemTest.php` (respect its existing style):

```php
public function testSyncConfigurationAccessors(): void
{
    $ticketSystem = new TicketSystem();

    self::assertNull($ticketSystem->getSyncUser());
    self::assertNull($ticketSystem->getSyncDefaultActivity());
    self::assertNull($ticketSystem->getWorklogSyncCursor());

    $user = new User();
    $activity = new Activity();
    $ticketSystem->setSyncUser($user)->setSyncDefaultActivity($activity)->setWorklogSyncCursor(1751871600000);

    self::assertSame($user, $ticketSystem->getSyncUser());
    self::assertSame($activity, $ticketSystem->getSyncDefaultActivity());
    self::assertSame(1751871600000, $ticketSystem->getWorklogSyncCursor());
}
```

(add `use App\Entity\Activity;` / `use App\Entity\User;` as needed.) Run `docker compose --profile dev exec app-dev php bin/phpunit tests/Entity/TicketSystemTest.php` — FAIL, undefined methods.

- [ ] **Step 2: Entity fields**

In `src/Entity/TicketSystem.php` after the `cloudId` property (match the file's attribute style; these are typed, non-secret — do NOT add to `SECRET_KEYS`):

```php
/**
 * TT user whose Jira token runs unattended incremental sync (ADR-023 §5).
 */
#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(name: 'sync_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
protected ?User $syncUser = null;

/**
 * Activity assigned to worklogs auto-imported by cron sync; null disables unattended import.
 */
#[ORM\ManyToOne(targetEntity: Activity::class)]
#[ORM\JoinColumn(name: 'sync_default_activity_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
protected ?Activity $syncDefaultActivity = null;

/**
 * Jira worklog/updated cursor (epoch milliseconds, Jira's `until`), advanced only after completed runs.
 */
#[ORM\Column(name: 'worklog_sync_cursor', type: 'bigint', nullable: true)]
protected ?int $worklogSyncCursor = null;
```

Fluent accessors in the file's getter/setter section (six methods, `static` returns). Imports: `use App\Entity\Activity;` — check whether `User` is already imported.

- [ ] **Step 3: Migration**

`migrations/Version20260709_TicketSystemSyncConfig.php`:

```php
final class Version20260709_TicketSystemSyncConfig extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-023 §5: ticket_systems sync user, default import activity, worklog cursor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems ADD sync_user_id INT DEFAULT NULL, ADD sync_default_activity_id INT DEFAULT NULL, ADD worklog_sync_cursor BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_systems ADD CONSTRAINT fk_ts_sync_user FOREIGN KEY (sync_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket_systems ADD CONSTRAINT fk_ts_sync_activity FOREIGN KEY (sync_default_activity_id) REFERENCES activities (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_systems DROP FOREIGN KEY fk_ts_sync_user');
        $this->addSql('ALTER TABLE ticket_systems DROP FOREIGN KEY fk_ts_sync_activity');
        $this->addSql('ALTER TABLE ticket_systems DROP COLUMN sync_user_id, DROP COLUMN sync_default_activity_id, DROP COLUMN worklog_sync_cursor');
    }
}
```

Mirror the three columns + the two FK-implied indexes in `sql/full.sql`'s `ticket_systems` CREATE TABLE (same style Phase 2 used for `users_ticket_systems`). Run migrate + `doctrine:schema:validate` (mapping must validate; only known cosmetic DB drift allowed — if the schema tool wants to DROP a new index, add the matching `#[ORM\Index]`/join-column mapping, the Phase 1+2 lesson).

- [ ] **Step 4: Test passes, gates, commit**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Entity/TicketSystemTest.php
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add migrations/Version20260709_TicketSystemSyncConfig.php src/Entity/TicketSystem.php sql/full.sql tests/Entity/TicketSystemTest.php
git commit -S --signoff -m "feat(sync): ticket system sync user, default import activity and worklog cursor (ADR-023 §5)"
```

---

### Task 2: Comment decode

**Files:**
- Modify: `src/Service/Sync/WorklogCommentCodec.php` (add static `decode`)
- Test: extend `tests/Service/Sync/WorklogCommentCodecTest.php`

**Interfaces:**
- Produces: `WorklogCommentCodec::decode(string $comment): string` — inverse of `encode` for pulls: strips a leading `#<digits>: <activity>: ` prefix (activity = shortest segment up to the next `: `); a comment not matching the TT format is returned unchanged (normalized).

- [ ] **Step 1: Failing tests**

```php
public function testDecodeStripsTtPrefix(): void
{
    self::assertSame('fixed the bug', WorklogCommentCodec::decode('#42: Development: fixed the bug'));
}

public function testDecodeKeepsColonsInsideDescription(): void
{
    self::assertSame('note: see FOO-1', WorklogCommentCodec::decode('#42: Development: note: see FOO-1'));
}

public function testDecodePassesThroughPlainJiraComments(): void
{
    self::assertSame('plain jira comment', WorklogCommentCodec::decode('plain jira comment'));
    self::assertSame('#123 not our format', WorklogCommentCodec::decode('#123 not our format'));
}

public function testDecodeNormalizes(): void
{
    self::assertSame("a\nb", WorklogCommentCodec::decode("  #42: Dev: a\r\nb "));
}
```

Run — FAIL, undefined method.

- [ ] **Step 2: Implement**

```php
/**
 * Inverse of encode() for pulls: extracts the description from a TT-format
 * comment ("#<id>: <activity>: <description>"); non-TT comments pass through.
 */
public static function decode(string $comment): string
{
    $normalized = self::normalize($comment);

    if (1 === preg_match('/^#\d+: [^:]*?: (?<description>.*)$/s', $normalized, $matches)) {
        return $matches['description'];
    }

    return $normalized;
}
```

(`use function preg_match;` per file conventions.)

- [ ] **Step 3: Pass, gates, commit**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/WorklogCommentCodecTest.php
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Sync/WorklogCommentCodec.php tests/Service/Sync/WorklogCommentCodecTest.php
git commit -S --signoff -m "feat(sync): comment decode for pull direction (ADR-023 §2)"
```

---

### Task 3: Feed + single-worklog read methods

**Files:**
- Modify: `src/DTO/Jira/JiraWorkLog.php` (add `?string $issueId`)
- Create: `src/DTO/Jira/JiraWorklogFeedPage.php`
- Modify: `src/Service/Integration/Jira/JiraOAuthApiService.php` (five additions after `getMyself()`)
- Test: extend `tests/Service/Integration/Jira/JiraOAuthApiServiceReadTest.php`, extend `tests/DTO/Jira/JiraWorkLogTest.php`

**Interfaces:**
- Produces on `JiraOAuthApiService`:
  - `getIssueWorklog(string $issueKey, int $worklogId): ?JiraWorkLog` — GET single; 404 (`JiraApiInvalidResourceException`) → null. **The lease read.**
  - `getWorklogsUpdatedSince(int $sinceMillis): JiraWorklogFeedPage` — `GET worklog/updated?since=<millis>`.
  - `getDeletedWorklogsSince(int $sinceMillis): JiraWorklogFeedPage` — `GET worklog/deleted?since=<millis>`.
  - `getWorklogsByIds(array $ids): array` (`list<int>` → `list<JiraWorkLog>`) — `POST worklog/list` which returns a JSON **array**; the existing `getResponse()` rejects non-objects, so add a protected `getResponseArray(string $url, array $data): array` beside it (same Guzzle/error handling, decodes to `list<object>`).
  - `getIssueKeyById(string $issueId): ?string` — `GET issue/{id}?fields=key` → `->key`; 404 → null. (Feed worklogs carry numeric `issueId`, not the key.)
- `JiraWorklogFeedPage` readonly: `(array $worklogIds /* list<int> */, int $until, bool $lastPage)` with `fromApiResponse(object): self` parsing `values[].worklogId`, `until`, `lastPage`.
- `JiraWorkLog` gains `?string $issueId` (parsed from `$data['issueId']`, scalar-cast to string).

- [ ] **Step 1: Failing tests**

`tests/DTO/Jira/JiraWorkLogTest.php` — append:

```php
public function testFromApiResponseParsesIssueId(): void
{
    self::assertSame('10042', JiraWorkLog::fromApiResponse((object) ['id' => 1, 'issueId' => 10042])->issueId);
    self::assertNull(JiraWorkLog::fromApiResponse((object) ['id' => 1])->issueId);
}
```

`JiraOAuthApiServiceReadTest.php` — append (the anonymous-subclass helper already overrides `get()` and `searchTicket()`; extend it to also override `getResponseArray()` via a new canned-array map — add a `private readonly array $arrayResponses` ctor param defaulting to `[]` and `protected function getResponseArray(string $url, array $data = []): array { return $this->arrayResponses[$url] ?? []; }`; for 404 simulation let `get()` throw when the canned value is the string `'404'`):

```php
public function testGetIssueWorklogReturnsSingleWorklog(): void
{
    $service = $this->serviceWithCannedResponses([
        'issue/ABC-1/worklog/77' => (object) ['id' => '77', 'started' => '2026-07-08T09:00:00.000+0200', 'timeSpentSeconds' => 600, 'updated' => '2026-07-08T10:00:00.000+0200'],
    ]);

    $workLog = $service->getIssueWorklog('ABC-1', 77);

    self::assertSame(77, $workLog?->id);
    self::assertSame('2026-07-08T10:00:00.000+0200', $workLog?->updated);
}

public function testGetIssueWorklogReturnsNullOn404(): void
{
    $service = $this->serviceWithCannedResponses(['issue/ABC-1/worklog/77' => '404']);

    self::assertNull($service->getIssueWorklog('ABC-1', 77));
}

public function testGetWorklogsUpdatedSinceParsesFeedPage(): void
{
    $service = $this->serviceWithCannedResponses([
        'worklog/updated?since=1000' => (object) [
            'values' => [(object) ['worklogId' => 11], (object) ['worklogId' => 12]],
            'until' => 2000,
            'lastPage' => false,
        ],
    ]);

    $page = $service->getWorklogsUpdatedSince(1000);

    self::assertSame([11, 12], $page->worklogIds);
    self::assertSame(2000, $page->until);
    self::assertFalse($page->lastPage);
}

public function testGetWorklogsByIdsPostsToWorklogList(): void
{
    $service = $this->serviceWithCannedResponses([], arrayResponses: [
        'worklog/list' => [(object) ['id' => '11', 'issueId' => 10001, 'timeSpentSeconds' => 60], (object) ['id' => '12', 'issueId' => 10002, 'timeSpentSeconds' => 120]],
    ]);

    $workLogs = $service->getWorklogsByIds([11, 12]);

    self::assertCount(2, $workLogs);
    self::assertSame('10001', $workLogs[0]->issueId);
}

public function testGetIssueKeyByIdResolvesKey(): void
{
    $service = $this->serviceWithCannedResponses(['issue/10001?fields=key' => (object) ['key' => 'ABC-1']]);

    self::assertSame('ABC-1', $service->getIssueKeyById('10001'));
}
```

Run — FAIL, undefined methods. (Adapt the helper's exact wiring to the file as it exists — the intent is canned per-URL responses, a throwing `get()` for `'404'`, and canned array responses for `getResponseArray`.)

- [ ] **Step 2: Implement**

`src/DTO/Jira/JiraWorklogFeedPage.php`:

```php
namespace App\DTO\Jira;

use function is_array;
use function is_numeric;
use function is_object;

/**
 * One page of Jira's worklog/updated or worklog/deleted feed (ADR-023 §5 read path 1).
 */
final readonly class JiraWorklogFeedPage
{
    /**
     * @param list<int> $worklogIds
     */
    public function __construct(
        public array $worklogIds,
        public int $until,
        public bool $lastPage,
    ) {
    }

    public static function fromApiResponse(object $response): self
    {
        /** @var array<string, mixed> $data */
        $data = (array) $response;

        $ids = [];
        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $value) {
                if (is_object($value) && isset($value->worklogId) && is_numeric($value->worklogId)) {
                    $ids[] = (int) $value->worklogId;
                }
            }
        }

        return new self(
            worklogIds: $ids,
            until: isset($data['until']) && is_numeric($data['until']) ? (int) $data['until'] : 0,
            lastPage: !isset($data['lastPage']) || true === $data['lastPage'],
        );
    }
}
```

`JiraWorkLog`: add `public ?string $issueId = null` as the last constructor property; in `fromApiResponse`: `issueId: isset($data['issueId']) && is_scalar($data['issueId']) ? (string) $data['issueId'] : null,`.

`JiraOAuthApiService` — append after `getMyself()` (imports: `JiraWorklogFeedPage`; `JiraApiInvalidResourceException` is already imported):

```php
/**
 * Single worklog read — the lease comparand (ADR-023 §1). Null when the worklog is gone.
 *
 * @throws JiraApiException
 */
public function getIssueWorklog(string $issueKey, int $worklogId): ?JiraWorkLog
{
    try {
        $response = $this->get(sprintf(JiraWorkLogService::WORKLOG_ITEM_URL_TEMPLATE, $issueKey, $worklogId));
    } catch (JiraApiInvalidResourceException) {
        return null;
    }

    return is_object($response) ? JiraWorkLog::fromApiResponse($response) : null;
}

/**
 * @throws JiraApiException
 */
public function getWorklogsUpdatedSince(int $sinceMillis): JiraWorklogFeedPage
{
    $response = $this->get('worklog/updated?since=' . $sinceMillis);

    return JiraWorklogFeedPage::fromApiResponse(is_object($response) ? $response : new stdClass());
}

/**
 * @throws JiraApiException
 */
public function getDeletedWorklogsSince(int $sinceMillis): JiraWorklogFeedPage
{
    $response = $this->get('worklog/deleted?since=' . $sinceMillis);

    return JiraWorklogFeedPage::fromApiResponse(is_object($response) ? $response : new stdClass());
}

/**
 * Bulk worklog fetch for feed ids (POST worklog/list returns a JSON array).
 *
 * @param list<int> $ids
 *
 * @return list<JiraWorkLog>
 *
 * @throws JiraApiException
 */
public function getWorklogsByIds(array $ids): array
{
    if ([] === $ids) {
        return [];
    }

    $workLogs = [];
    foreach ($this->getResponseArray('worklog/list', ['ids' => $ids]) as $workLog) {
        if (is_object($workLog)) {
            $workLogs[] = JiraWorkLog::fromApiResponse($workLog);
        }
    }

    return $workLogs;
}

/**
 * Resolves a numeric issue id (as found in feed worklogs) to the issue key.
 *
 * @throws JiraApiException
 */
public function getIssueKeyById(string $issueId): ?string
{
    try {
        $response = $this->get(sprintf('issue/%s?fields=key', $issueId));
    } catch (JiraApiInvalidResourceException) {
        return null;
    }

    return is_object($response) && isset($response->key) && is_string($response->key) ? $response->key : null;
}
```

And the array-decoding sibling of `getResponse()` (place next to it, mirroring its Guzzle call and error mapping exactly — same 401/404/other handling; only the decode branch differs):

```php
/**
 * Like getResponse(), for endpoints returning a JSON array (e.g. POST worklog/list).
 *
 * @param array<string, mixed> $data
 *
 * @return list<object>
 *
 * @throws JiraApiException
 */
protected function getResponseArray(string $url, array $data = []): array
{
    // copy getResponse()'s request/catch structure verbatim; in the success
    // branch: $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
    // return is_array($decoded) ? array_values(array_filter($decoded, is_object(...))) : [];
}
```

Implement the body by copying `getResponse()` (`:683-714`) and adjusting only the decode/return; do not refactor the original.

- [ ] **Step 3: Pass, gates, commit**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Integration/Jira tests/DTO/Jira
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/DTO/Jira src/Service/Integration/Jira/JiraOAuthApiService.php tests/DTO/Jira tests/Service/Integration/Jira
git commit -S --signoff -m "feat(jira): worklog feed, single-worklog and issue-id read methods (ADR-023 §5)"
```

---

### Task 4: WorklogWriteService — the lease

**Files:**
- Create: `src/Service/Sync/WorklogWriteService.php`, `src/Enum/WriteOutcome.php`
- Test: `tests/Service/Sync/WorklogWriteServiceTest.php`

**Interfaces:**
- `WriteOutcome` enum: `WRITTEN = 'written'`, `LEASE_LOST = 'lease_lost'`, `REMOTE_MISSING = 'remote_missing'`, `SKIPPED = 'skipped'`.
- `WorklogWriteService::push(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome`:
  1. Guards mirror the legacy write's cheap ones: ticket `''`/`'0'` → SKIPPED.
  2. `$entry->getWorklogId()` null/≤0 → **create path**: `$api->updateEntryJiraWorkLog($entry)` (legacy method creates + stores id), then refresh base (below) → WRITTEN.
  3. Else **update path**: `$remote = $api->getIssueWorklog($entry->getTicket(), $worklogId)`:
     - null → REMOTE_MISSING; if a `WorklogSyncState` exists set status `ORPHANED` (no write, no delete). Do NOT null the entry's worklogId (the sync engine owns move detection).
     - state exists AND `$remote->updated !== $state->getBaseUpdatedAt()` → LEASE_LOST; set status `CONFLICT`, `conflictRemotePayload = ['remote' => normalizer-less raw: comment/started/timeSpentSeconds/updated]` (store the raw four fields — display material). No write.
     - state missing (pre-ADR entry) → **base bootstrapping**: proceed as if lease passed (policy decision 1).
     - lease passed → `$api->updateEntryJiraWorkLog($entry)` → refresh base → WRITTEN.
  4. **Refresh base** = `$freshRemote = $api->getIssueWorklog($entry->getTicket(), (int) $entry->getWorklogId())`; if non-null: upsert `WorklogSyncState` (create if missing) with `basePayload = RemoteWorklogNormalizer->normalize($freshRemote, $entry->getTicket())->toArray()`, `baseUpdatedAt = $freshRemote->updated ?? ''`, status `IN_SYNC`, `lastSyncedAt = now`. Persist (no flush — the caller flushes, same contract as the legacy write).
- `delete(JiraOAuthApiService $api, Entry $entry): void` — thin: `$api->deleteEntryJiraWorkLog($entry)` (legacy already 404-tolerant). Lease-checking deletes is deliberately NOT done: the local entry is already gone; parking has nothing to park against. Document in the ADR note (Task 9).
- Constructor: `(EntityManagerInterface, WorklogSyncStateRepository, RemoteWorklogNormalizer, ClockInterface)`.

- [ ] **Step 1: Failing tests** — full file, mock kit like other sync tests (`JiraOAuthApiService` mock; `Entry` stubs with ticket/worklogId; `WorklogSyncStateRepository` mock returning a real `WorklogSyncState` where needed):

Cases (write each as a full test method):
1. `testEmptyTicketSkips` — ticket `''` → SKIPPED, api never called (`$api->expects(self::never())->method('getIssueWorklog')`).
2. `testCreatePathWritesAndSeedsBase` — worklogId null; api `updateEntryJiraWorkLog` once; after it, stub `$entry->getWorklogId()` returning 77 (use `willReturnOnConsecutiveCalls(null, null, 77, 77)` or a real `Entry`); `getIssueWorklog` returns a fresh worklog with `updated: 'U1'`; assert WRITTEN and a persisted `WorklogSyncState` with `getBaseUpdatedAt() === 'U1'` and status IN_SYNC (collect persists via `willReturnCallback`).
3. `testLeaseLostParksConflict` — worklogId 77, state with `baseUpdatedAt 'U0'`; `getIssueWorklog` returns `updated 'U9'`; assert LEASE_LOST, state status CONFLICT, `conflictRemotePayload` non-null, `updateEntryJiraWorkLog` never called.
4. `testLeasePassesWritesAndRefreshesBase` — state `baseUpdatedAt 'U1'`, remote `updated 'U1'`; write called once; second `getIssueWorklog` returns `updated 'U2'`; assert WRITTEN and state base `'U2'`, status IN_SYNC.
5. `testRemoteMissingMarksOrphaned` — `getIssueWorklog` → null; assert REMOTE_MISSING, state status ORPHANED, no write, entry worklogId untouched.
6. `testMissingStateBootstrapsBase` — worklogId 77, repository returns null state, remote exists; write called; assert WRITTEN and a NEW persisted state with base from the fresh read.

Use a real `Entry` where consecutive-call stubbing gets awkward (Entry is not final). `getIssueWorklog` is called twice in the happy paths — use `willReturnOnConsecutiveCalls`.

- [ ] **Step 2: Implement** (complete class):

```php
namespace App\Service\Sync;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\WorklogSyncState;
use App\Enum\WorklogSyncStatus;
use App\Enum\WriteOutcome;
use App\Repository\WorklogSyncStateRepository;
use App\Service\Integration\Jira\JiraOAuthApiService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Lease-checked (read-compare-write) Jira worklog writes (ADR-023 §1). Wraps the legacy
 * write (which owns payload format, guards and id storage) with the CAS protocol and
 * base-state maintenance. The ~seconds window between compare and write is accepted.
 */
class WorklogWriteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorklogSyncStateRepository $worklogSyncStateRepository,
        private readonly RemoteWorklogNormalizer $remoteWorklogNormalizer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function push(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): WriteOutcome
    {
        $ticket = $entry->getTicket();
        if ('' === $ticket || '0' === $ticket) {
            return WriteOutcome::SKIPPED;
        }

        $worklogId = $entry->getWorklogId();
        if (null === $worklogId || $worklogId <= 0) {
            $api->updateEntryJiraWorkLog($entry);
            $this->refreshBase($api, $entry, $ticketSystem);

            return WriteOutcome::WRITTEN;
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        $remote = $api->getIssueWorklog($ticket, $worklogId);

        if (null === $remote) {
            if ($state instanceof WorklogSyncState) {
                $state->setStatus(WorklogSyncStatus::ORPHANED);
            }

            return WriteOutcome::REMOTE_MISSING;
        }

        if ($state instanceof WorklogSyncState && ($remote->updated ?? '') !== $state->getBaseUpdatedAt()) {
            $state->setStatus(WorklogSyncStatus::CONFLICT);
            $state->setConflictRemotePayload([
                'comment' => $remote->comment,
                'started' => $remote->started,
                'timeSpentSeconds' => $remote->timeSpentSeconds,
                'updated' => $remote->updated,
            ]);

            return WriteOutcome::LEASE_LOST;
        }

        // State missing = pre-ADR entry: bootstrap the base with this first lease-era write.
        $api->updateEntryJiraWorkLog($entry);
        $this->refreshBase($api, $entry, $ticketSystem);

        return WriteOutcome::WRITTEN;
    }

    public function delete(JiraOAuthApiService $api, Entry $entry): void
    {
        $api->deleteEntryJiraWorkLog($entry);
    }

    private function refreshBase(JiraOAuthApiService $api, Entry $entry, TicketSystem $ticketSystem): void
    {
        $worklogId = $entry->getWorklogId();
        if (null === $worklogId || $worklogId <= 0) {
            return;
        }

        $fresh = $api->getIssueWorklog($entry->getTicket(), $worklogId);
        if (null === $fresh) {
            return;
        }

        $state = $this->worklogSyncStateRepository->findOneBy(['entry' => $entry]);
        if (!$state instanceof WorklogSyncState) {
            $state = new WorklogSyncState()->setEntry($entry)->setTicketSystem($ticketSystem);
            $this->entityManager->persist($state);
        }

        $state->setStatus(WorklogSyncStatus::IN_SYNC)
            ->setBasePayload($this->remoteWorklogNormalizer->normalize($fresh, $entry->getTicket())->toArray())
            ->setBaseUpdatedAt($fresh->updated ?? '')
            ->setConflictRemotePayload(null)
            ->setLastSyncedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
```

`WriteOutcome` enum in `src/Enum/WriteOutcome.php` (four cases as above). Note: zero-duration entries flow through the legacy write which deletes the worklog — the outcome is still WRITTEN and `refreshBase` no-ops because `worklogId` was nulled; that preserves existing semantics.

- [ ] **Step 3: Pass, gates, commit**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/WorklogWriteServiceTest.php
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/Enum/WriteOutcome.php src/Service/Sync/WorklogWriteService.php tests/Service/Sync/WorklogWriteServiceTest.php
git commit -S --signoff -m "feat(sync): lease-checked worklog write service (ADR-023 §1)"
```

---

### Task 5: EntryEventSubscriber upgrade — one write discipline

**Files:**
- Modify: `src/EventSubscriber/EntryEventSubscriber.php`
- Modify: `tests/EventSubscriber/EntryEventSubscriberTest.php`

**Interfaces:**
- Constructor gains `WorklogWriteService $worklogWriteService` (before the nullable logger). `bookWorklog()` changes exactly two calls:
  - `$jiraOAuthApiService->deleteEntryJiraWorkLog($previousEntry)` → `$this->worklogWriteService->delete($jiraOAuthApiService, $previousEntry)` (behavior identical).
  - `$jiraOAuthApiService->updateEntryJiraWorkLog($entry)` → `$outcome = $this->worklogWriteService->push($jiraOAuthApiService, $entry, $ticketSystem);` followed by outcome handling:
    ```php
    if (WriteOutcome::LEASE_LOST === $outcome) {
        $this->logger?->info('Worklog push parked as conflict: remote changed since last sync', ['entry' => $entry->getId()]);
    } elseif (WriteOutcome::REMOTE_MISSING === $outcome) {
        $this->logger?->info('Worklog push parked: remote worklog gone (orphaned)', ['entry' => $entry->getId()]);
    }
    ```
  The existing `flush()` after stays (it now also persists state changes made by the service). `deleteWorklog()` (entry deletion) swaps `$api->deleteEntryJiraWorkLog($entry)` → `$this->worklogWriteService->delete($api, $entry)`. Everything else — gates, remap, cache invalidation, error logging — untouched.

- [ ] **Step 1: Update the test file FIRST (red)**

In `tests/EventSubscriber/EntryEventSubscriberTest.php`:
1. setUp: create `WorklogWriteService&MockObject $worklogWriteService` and pass it as the new constructor argument (check the exact position you add it — keep logger last; update every `new EntryEventSubscriber(...)` call site including the constructor-without-logger test).
2. Every existing assertion `$this->jiraApi->expects(self::once())->method('updateEntryJiraWorkLog')` becomes `$this->worklogWriteService->expects(self::once())->method('push')->with($this->jiraApi, $entry, self::anything())->willReturn(WriteOutcome::WRITTEN)`; `deleteEntryJiraWorkLog` assertions on the subscriber's paths become `->method('delete')->with($this->jiraApi, $entry)`. Negative gates (`expectNoJiraApi`) additionally assert `$this->worklogWriteService->expects(self::never())->method('push')`.
3. Error-logging tests: make `push` throw instead of `updateEntryJiraWorkLog`.
4. NEW tests:
   ```php
   public function testLeaseLostLogsParkedConflictAndDoesNotThrow(): void
   // push returns WriteOutcome::LEASE_LOST → logger->info with 'parked as conflict' substring; flush still called once.

   public function testRemoteMissingLogsOrphaned(): void
   // push returns WriteOutcome::REMOTE_MISSING → logger->info 'orphaned'.
   ```
Run the file — FAIL (constructor signature).

- [ ] **Step 2: Implement the subscriber change** (as specified in Interfaces — the diff is small; do not touch `remapEntryToInternalTicket`, `shouldAutoSync`, `canBookOn`, `bookableTicketSystems`).

- [ ] **Step 3: Pass + full controller suite** (the subscriber runs in save flows):

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/EventSubscriber/EntryEventSubscriberTest.php
docker compose --profile dev exec app-dev composer test:fast
```

- [ ] **Step 4: Gates, commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/EventSubscriber/EntryEventSubscriber.php tests/EventSubscriber/EntryEventSubscriberTest.php
git commit -S --signoff -m "feat(sync): entry save path uses lease-checked writes; lease loss parks instead of clobbering (ADR-023 §5)"
```

---

### Task 6: EntryPullApplier — remote → entry

**Files:**
- Create: `src/Service/Sync/EntryPullApplier.php`, `src/ValueObject/Sync/PullResult.php`
- Test: `tests/Service/Sync/EntryPullApplierTest.php`

**Interfaces:**
- `PullResult` readonly: `(bool $applied, string $reason = '', array $affectedDays = [] /* list<string> Y-m-d */)`.
- `EntryPullApplier::apply(Entry $entry, WorklogSnapshot $remote, array $fields /* list<WorklogField> */, TicketSystem $ticketSystem): PullResult` — applies exactly the given remote-changed fields:
  - `ISSUE_KEY`: `TicketProjectResolver::resolve($remote->issueKey, $ticketSystem)`; no project → `PullResult(false, 'target project unresolved: <reason>')` (nothing applied). Else `setTicket`, `setProject`, `setCustomer($project->getCustomer())` when instance.
  - `STARTED` and/or `DURATION`: recompute day/start/end: day+start from `$remote->startedTimestamp` (server TZ, same `new DateTime()->setTimestamp()` idiom as import), duration = `$remote->durationMinutes`, `end = start + duration`; midnight-crossing → `PullResult(false, 'worklog crosses midnight')`. Apply via `setDay('Y-m-d')`, `setStart('H:i:s')`, `setEnd('H:i:s')`, `setDuration(...)` — in that order (`setStart/setEnd` re-anchor to day; duration is set explicitly last because nothing recomputes it).
  - When only `DURATION` changed (STARTED clean): keep the entry's existing day/start, recompute `end = existing start + new duration` (midnight guard again).
  - `COMMENT`: `setDescription(WorklogCommentCodec::decode($remote->comment))`.
  - `affectedDays`: unique list of the entry's day BEFORE and AFTER (both, when STARTED moved it).
  - Validation is all-or-nothing: run all failable checks (project resolution, midnight) BEFORE mutating the entry.
- Constructor: `(TicketProjectResolver $ticketProjectResolver)`.

- [ ] **Step 1: Failing tests** — cases (full methods; real `Entry` seeded with `setDay('2026-06-15')->setStart('09:00:00')->setEnd('10:00:00')` + `setDuration(60)` + `setTicket('ABC-1')` + `setDescription('old')`):
1. `testCommentPullDecodesTtFormat` — fields `[COMMENT]`, remote comment `'#42: Dev: new text'` → description `'new text'`; day/start untouched; affectedDays = `['2026-06-15']`.
2. `testStartedPullMovesDayAndReportsBothDays` — fields `[STARTED]`, remote ts on 2026-06-16 08:00 → day/start moved, end = start+60min, affectedDays contains both days.
3. `testDurationPullExtendsEnd` — fields `[DURATION]`, remote duration 90 → duration 90, end `10:30:00`.
4. `testIssueKeyPullRemapsProject` — fields `[ISSUE_KEY]`, resolver returns project stub with customer → ticket/project/customer updated.
5. `testIssueKeyPullFailsWhenUnresolved` — resolver returns `ProjectResolution(null, 'no project ...')` → `applied === false`, entry ticket UNCHANGED.
6. `testMidnightCrossingFails` — fields `[STARTED]` with remote ts 23:30 + duration 60 → applied false, entry unchanged.

- [ ] **Step 2: Implement** — complete class following the interface block; structure:

```php
public function apply(Entry $entry, WorklogSnapshot $remote, array $fields, TicketSystem $ticketSystem): PullResult
{
    $pullIssueKey = in_array(WorklogField::ISSUE_KEY, $fields, true);
    $pullStarted = in_array(WorklogField::STARTED, $fields, true);
    $pullDuration = in_array(WorklogField::DURATION, $fields, true);
    $pullComment = in_array(WorklogField::COMMENT, $fields, true);

    $project = null;
    if ($pullIssueKey) {
        $resolution = $this->ticketProjectResolver->resolve($remote->issueKey, $ticketSystem);
        $project = $resolution->project;
        if (!$project instanceof Project) {
            return new PullResult(false, 'target project unresolved: ' . $resolution->reason);
        }
    }

    $dayBefore = $entry->getDay()->format('Y-m-d');

    // compute new times before mutating
    $newDuration = $pullDuration ? $remote->durationMinutes : $entry->getDuration();
    if ($pullStarted) {
        $start = new DateTime()->setTimestamp($remote->startedTimestamp);
    } else {
        $start = DateTime::createFromInterface($entry->getDay());
        $start->setTime((int) $entry->getStart()->format('H'), (int) $entry->getStart()->format('i'), (int) $entry->getStart()->format('s'));
    }

    $end = (clone $start)->modify(sprintf('+%d minutes', $newDuration));
    if (($pullStarted || $pullDuration) && $end->format('Y-m-d') !== $start->format('Y-m-d')) {
        return new PullResult(false, 'worklog crosses midnight');
    }

    // mutate
    if ($pullIssueKey && $project instanceof Project) {
        $entry->setTicket($remote->issueKey)->setProject($project);
        $customer = $project->getCustomer();
        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }
    }

    if ($pullStarted || $pullDuration) {
        $entry->setDay($start->format('Y-m-d'))
            ->setStart($start->format('H:i:s'))
            ->setEnd($end->format('H:i:s'))
            ->setDuration($newDuration);
    }

    if ($pullComment) {
        $entry->setDescription(WorklogCommentCodec::decode($remote->comment));
    }

    $dayAfter = $entry->getDay()->format('Y-m-d');

    return new PullResult(true, '', array_values(array_unique([$dayBefore, $dayAfter])));
}
```

- [ ] **Step 3: Pass, gates, commit**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/EntryPullApplierTest.php
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Sync/EntryPullApplier.php src/ValueObject/Sync/PullResult.php tests/Service/Sync/EntryPullApplierTest.php
git commit -S --signoff -m "feat(sync): pull applier writing remote worklog changes into entries (ADR-023 §2)"
```

---

### Task 7: SyncWorklogsService — the matrix with writes

**Files:**
- Create: `src/Service/Sync/SyncWorklogsService.php`
- Modify: `src/Service/Sync/ImportWorklogsService.php` — change `processWorklog` visibility private → **public** (one keyword; sync delegates unmatched worklogs to it) and nothing else.
- Test: `tests/Service/Sync/SyncWorklogsServiceTest.php`

**Interfaces:**
- `SyncWorklogsService extends AbstractSyncRunService`; constructor `(EntityManagerInterface, EntryRepository, WorklogSyncStateRepository, JiraOAuthApiFactory, EntryWorklogProjector, RemoteWorklogNormalizer, ReconciliationService, WorklogWriteService, EntryPullApplier, ImportWorklogsService, JiraAuthorMapper, DayClassService, ClockInterface)` (parent gets em+clock).
- `sync(TicketSystem $ticketSystem, ?int $sinceMillisOverride = null, bool $dryRun = false): SyncRun`:
  1. Sync user: `$ticketSystem->getSyncUser()`; null → run FAILED (`throw new InvalidArgumentException('No sync user configured for ticket system ...')`). Run `triggeredBy` = sync user; type `SyncRunType::SYNC`; scope `{since, dry_run}`.
  2. Cursor: `$since = $sinceMillisOverride ?? $ticketSystem->getWorklogSyncCursor()`; null → FAILED (`'No cursor yet; pass --since for the first run'`).
  3. **Feeds:** page through `getWorklogsUpdatedSince($since)` following `until` while `!lastPage`, max 20 pages (cap hit → TRUNCATED item); same for `getDeletedWorklogsSince`. Track `$newCursor = max(until values)`.
  4. **Updated ids** → `getWorklogsByIds` in chunks of 1000 → for each worklog:
     - resolve issue key: `$worklog->issueId` → cached `getIssueKeyById`; unresolvable → ERROR item, `errors` counter, skip.
     - normalize (catch → ERROR item), `$entry = entryRepository->findOneByWorklogIdAndTicketSystem((int) $worklog->id, $ticketSystem)`.
     - **entry found** → `reconcileAndExecute(...)` (below).
     - **entry not found** → remember in `$unmatchedRemote[worklogId] = [worklog, snapshot, issueKey]` (move-detection pool + import pool).
  5. **Deleted ids** → for each: `$entry = findOneByWorklogIdAndTicketSystem(...)`; none → skip (never knew it). Else **move detection first**: an unmatched-remote candidate with same author (`JiraUserIdentity`-less compare: `authorAccountId ?? authorName` equal to the mapped author of the entry's user? — simpler and sufficient: same `startedTimestamp` AND same `durationMinutes` as the ENTRY's projection) → **relink**: `entry->setWorklogId(newId)`, apply `ISSUE_KEY` pull if the issue changed (via EntryPullApplier), refresh state base from the candidate (`basePayload = candidate snapshot`, `baseUpdatedAt = candidate updated`), remove from `$unmatchedRemote`, counter `relinked`, item kind `LOCAL_ONLY` is wrong here — use counter only. No match → matrix row: local clean vs base (`projector->project($entry)->equals(base)`) → **delete entry** (`$entityManager->remove($entry)`, state cascades), counter `deleted_local`, item (kind `LOCAL_ONLY`, reason `'remote worklog deleted; local entry removed'`), day-recalc queued; local dirty or no base → state→`ORPHANED` (create state if missing is NOT possible without base — then just item) + item kind `LOCAL_ONLY` reason `'remote worklog deleted; local entry modified — parked'`, counter `orphaned`.
  6. **Import leftover unmatched** (`$unmatchedRemote` after move detection): if `$ticketSystem->getSyncDefaultActivity()` set → build one `ImportRunContext` (syncRun, ticketSystem, that activity, `targetUsernames: []`, `dryRun`, range covering everything: `rangeFrom: 0`, `rangeTo: PHP_INT_MAX`) and call `$this->importWorklogsService->processWorklog($ctx, $issueKey, $worklog)` per leftover; afterwards merge `$ctx->affectedDays` into the recalc queue. Else → REMOTE_ONLY item + counter per leftover.
  7. Flush; day-class recalc per affected (user, day) — same post-flush id rule as import; cursor: `if (!$dryRun && $newCursor > 0) { $ticketSystem->setWorklogSyncCursor($newCursor); }` (inside the run body so a FAILED run never advances it); final flush happens in `executeRun`.
- `reconcileAndExecute(SyncRun, ImportRunContext-like context..., Entry $entry, WorklogSnapshot $remoteSnapshot, JiraWorkLog $worklog, string $issueKey, bool $dryRun): void`:
  - base from state (may be null), local = projection, decision = `reconcile(base, local, remote)`.
  - `NONE` → counter `in_sync`; if state missing → seed base (not in dry-run): create state with `basePayload = remoteSnapshot`, `baseUpdatedAt = worklog->updated ?? ''` (bootstrap for equal pairs).
  - `PUSH` → dry-run: counter `would_push`; else `WorklogWriteService::push($apiFor($entry), $entry, $ticketSystem)` mapping outcome → counters `pushed` / `conflicts` (LEASE_LOST — the service already parked) / `orphaned` (REMOTE_MISSING) with items for the non-WRITTEN outcomes.
  - `PULL` → dry-run: `would_pull`; else `EntryPullApplier::apply($entry, $remoteSnapshot, $decision->fields, $ticketSystem)`: applied → refresh state (base = remoteSnapshot, updated, IN_SYNC), counter `pulled`, queue affected days; not applied → item ERROR with the reason, counter `errors`.
  - `MERGE` → dry-run `would_merge`; else: first apply the REMOTE-changed fields (`$base->diff($remoteSnapshot)`) via the pull applier, then push the whole entry lease-checked (`push(...)` — the lease compares against the CURRENT remote `updated` which is exactly what we just read... the write service re-GETs; outcome WRITTEN refreshes base). Counters `merged` or error/conflict per outcome.
  - `CONFLICT` → park: state (create if missing with `basePayload` kept as-is or, when no state, DON'T create — record item only) status CONFLICT + `conflictRemotePayload` `{comment/started/timeSpentSeconds/updated from $worklog}` when state exists; item kind CONFLICT with fields payload; counter `conflicts`.
  - `DIVERGED` (no base, differs) → item DIVERGED, counter `diverged`, no writes (resolution is Phase 4).
  - Token selection for `$apiFor($entry)`: entry owner when connected — owner's `UserTicketsystem` row exists, has non-empty `getAccessToken()`, and `!getAvoidConnection()` → `jiraOAuthApiFactory->create($owner, $ticketSystem)`, else the run's sync-user api instance. Cache per user id.
- Counter keys: `in_sync, pushed, pulled, merged, relinked, deleted_local, orphaned, conflicts, diverged, errors, remote_only, would_push, would_pull, would_merge` (+ whatever import contributes).

- [ ] **Step 1: Failing tests** — the essential behavioral matrix (each a full method; mock kit mirrors `ImportWorklogsServiceTest`, plus mocks for `WorklogWriteService`, `EntryPullApplier`, `ImportWorklogsService`, feeds):

1. `testNoSyncUserFailsRun`
2. `testNoCursorAndNoOverrideFailsRun`
3. `testInSyncPairSeedsBaseWhenStateMissing` (assert persisted WorklogSyncState)
4. `testLocalDirtyPushesViaLeaseService` (write service `push` once, WRITTEN → counter `pushed`)
5. `testRemoteDirtyPullsAndRefreshesBase` (pull applier once with the remote-diff fields; state base updated; counter `pulled`)
6. `testConflictParksWithRemotePayload` (state status CONFLICT, item kind CONFLICT, no push/pull calls)
7. `testDeletedFeedRemovesCleanEntry` (`$entityManager->expects(self::once())->method('remove')`, counter `deleted_local`)
8. `testDeletedFeedParksDirtyEntryAsOrphaned` (no remove; state ORPHANED; counter `orphaned`)
9. `testMoveRelinksDeletePlusCreatePair` (deleted id X + unmatched new worklog with SAME started+duration → `setWorklogId(newId)`, counter `relinked`, no remove, no import)
10. `testUnmatchedRemoteImportedWhenActivityConfigured` (`importWorklogsService->processWorklog` called)
11. `testUnmatchedRemoteReportedWhenNoActivity` (REMOTE_ONLY item, import never called)
12. `testCursorAdvancesOnlyOnRealCompletedRun` (dry-run: `setWorklogSyncCursor` never; real run: set to feed's `until`)
13. `testDryRunNeverWrites` (remove/push/pull/import never called; `would_*` counters populated)

Build feed stubs: `$this->api->method('getWorklogsUpdatedSince')->willReturn(new JiraWorklogFeedPage([11], 2000, true));` etc. Use real `Entry`/`WorklogSyncState` objects where mutation is asserted.

- [ ] **Step 2: Implement** the service following the interface contract exactly. Keep `run()` under control by splitting: `collectFeed(...)`, `processUpdatedWorklog(...)`, `processDeletedWorklog(...)`, `handleUnmatched(...)`, `reconcileAndExecute(...)`, `apiForEntry(...)` — same decomposition discipline SonarCloud forced on import (complexity ≤15 per method, ≤7 params; bundle run-scoped state in a small `SyncRunContext` final class in `src/Service/Sync/` mirroring `ImportRunContext`: syncRun, ticketSystem, api, dryRun, unmatchedRemote, affectedDays, userApiCache, newCursor).

- [ ] **Step 3: Pass + full unit suite**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Service/Sync/SyncWorklogsServiceTest.php
docker compose --profile dev exec app-dev composer test:unit
```

- [ ] **Step 4: Gates, commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
git add src/Service/Sync tests/Service/Sync
git commit -S --signoff -m "feat(sync): incremental bidirectional sync engine executing the ADR-023 matrix"
```

---

### Task 8: `tt:sync-worklogs` command

**Files:**
- Create: `src/Command/TtSyncWorklogsCommand.php`
- Test: `tests/Command/TtSyncWorklogsCommandTest.php`

**Interfaces:**
- `tt:sync-worklogs <ticket-system-id> [--since=<Y-m-d|epoch-ms>] [--dry-run]`; exit 0 on COMPLETED else 1. Uses `SyncRunConsoleRenderer` (label `'Sync'`). `--since` accepts `Y-m-d` (converted to epoch ms at 00:00) or a raw integer (ms).

- [ ] **Step 1: Failing tests** (mirror `TtImportWorklogsCommandTest` structure — mocked `SyncWorklogsService` + `ManagerRegistry` + real renderer):
1. `testRunsAndPrintsCounters` — COMPLETED run with `['pushed' => 2, 'pulled' => 1]` → exit 0, display contains `pushed`.
2. `testUnknownTicketSystemFails` — exit 1.
3. `testSinceDateIsConvertedToMillis` — `--since=2026-07-01` → `sync(...)` receives `self::callback(fn (int $ms) => $ms === (new DateTimeImmutable('2026-07-01'))->getTimestamp() * 1000)`.
4. `testInvalidSinceFails` — `--since=nope` → exit 1, 'Invalid --since'.
5. `testFailedRunExitsNonZero`.

- [ ] **Step 2: Implement** (invokable, `#[AsCommand(name: 'tt:sync-worklogs', description: 'Incremental bidirectional Jira worklog sync (ADR-023)')]`; `--since` parsing: `ctype_digit` → `(int)`, else `new DateTimeImmutable($since)` in try/catch → `getTimestamp() * 1000`).

- [ ] **Step 3: Pass, smoke `bin/console list tt` (three worklog commands listed), gates, commit**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Command/TtSyncWorklogsCommandTest.php
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:fast
git add src/Command/TtSyncWorklogsCommand.php tests/Command/TtSyncWorklogsCommandTest.php
git commit -S --signoff -m "feat(sync): tt:sync-worklogs incremental cron command (ADR-023 §6)"
```

---

### Task 9: ADR + docs update

**Files:**
- Modify: `docs/adr/ADR-023-jira-worklog-bidirectional-sync.md`, `docs/adr/README.md`
- Modify: `docs/subticket-sync.md` sibling — create `docs/worklog-sync.md` (cron setup for `tt:sync-worklogs`, modeled on the subticket doc's crontab/systemd examples; cover: configuring the sync user + default import activity, first-run `--since`, dry-run, what gets parked and where to see it)

- [ ] **Step 1: ADR amendments**

1. Status → `Accepted — Phases 1–3 (verify, import, bidirectional sync) implemented; Phase 4 (API/MCP/UI surfaces) pending.`
2. §1 append: `Implementation note (Phase 3): lease-checked writes wrap the legacy write path. Entries created before ADR-023 have no base; their first lease-era write performs the write and seeds the base ("base bootstrapping") instead of parking. Local deletes are pushed without a lease check — the local entry is already gone, so there is nothing to park a conflict against.`
3. §5 append: `Implementation note (Phase 3): unattended cron import requires both a sync user and a per-ticket-system default import activity (ticket_systems.sync_default_activity_id); without it, Jira-born worklogs surface as remote_only items for manual import.`
4. §7 remaining verification point 5 (`worklog/updated` availability) — resolve: `**Resolved (Phase 3):** implemented against the documented Server/DC + Cloud shape (values[].worklogId, until, lastPage); page cap 20 with explicit TRUNCATED reporting.`
5. README index row → `Accepted (Phases 1–3 done; surfaces pending)`.

- [ ] **Step 2: Write `docs/worklog-sync.md`** (concise operator doc — configuration, first run, cron example copied in style from `docs/subticket-sync.md:59-67`, parked-item semantics, rollback note: disable by clearing the sync user).

- [ ] **Step 3: Commit**

```bash
git add docs/adr docs/worklog-sync.md
git commit -S --signoff -m "docs(adr): ADR-023 phase 3 implemented; operator guide for worklog sync"
```

---

## Phase boundary (orientation — NOT this plan)

- **Phase 4:** v2 REST endpoints (`/api/v2/worklog-sync/*`, PAT scopes `worklog-sync:*`), MCP tools (`sync_jira_worklogs`, `get_sync_run`, `list_sync_conflicts`, `resolve_sync_conflict`), conflict resolution (forced lease-checked write re-entering the engine), SPA UI (self-service import, admin runs, conflict screen), chunked/resumable HTTP runs (`SyncRun.continuation`).
