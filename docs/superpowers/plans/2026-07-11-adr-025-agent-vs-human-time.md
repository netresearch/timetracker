# ADR-025 Agent vs Human Time — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record agent wall-clock and human effort as distinct, non-conflated streams on the same `entries` table, so agent time is logged completely yet never leaks into human labour totals, attendance, or working-time-law calculations.

**Architecture:** One additive `source` enum column (`human`|`agent`) on `Entry`, plus `logged_by`, `estimated`, a `responsible_user` FK, and a `touchpoints` JSON. The agent (via the MCP `log_time` tool under its PAT) writes BOTH a `source=agent` wall-clock entry and a delegated `source=human` estimated entry. Every duration *consumer* is made source-aware: human-only where it feeds attendance/capacity/day totals; source-sliced (never summed across) where it feeds controlling.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, PHPUnit 13. Reads ADR-025 (model), ADR-022 (v2 API), ADR-021 (PATs), ADR-024 (Personio export = the ArbZG boundary).

## Global Constraints

- PHP 8.5, `declare(strict_types=1)`, typed params/returns; PHPStan **level 10**.
- All four CI gates must pass before every push: `bin/phpstan analyze` (full tree), `bin/phpstan analyze -c config/quality/phpat.neon`, `bin/php-cs-fixer fix --dry-run`, `bin/rector process src --config=config/quality/rector.php --dry-run`.
- Run everything in the dev container: `docker compose --profile dev exec -T app-dev sh -c '…'`. Functional/repository tests need the unittest DB — prefix with `DATABASE_URL="mysql://unittest:unittest@db_unittest:3306/unittest?serverVersion=mariadb-12.1.2&charset=utf8mb4" APP_ENV=test` (the `.env.test.local` trap otherwise points at the dev DB).
- New enums get a `Valid(): bool` method + a `default` case per the repo-wide Defensive-Enum rule, even though existing enums omit it.
- Backward compatibility is mandatory: every existing row and every un-migrated caller must behave as `source=human` — the column default and every new DTO field default to the human/legacy value.
- Migration naming: `migrations/Version<YYYYMMDD>_<Name>.php`, `final class … extends AbstractMigration`. Mirror `sql/full.sql` (the non-Doctrine base schema).

---

## File Structure

- **Create** `src/Enum/EntrySource.php` — the `human|agent` backed enum (+ `Valid()`, label).
- **Modify** `src/Entity/Entry.php` — 5 new mapped fields + getters/setters + `toArray()`.
- **Create** `migrations/Version20260711_EntrySourceAttribution.php` — additive DDL. **Modify** `sql/full.sql` (entries DDL).
- **Modify** `src/Dto/EntrySaveDto.php`, `src/Dto/BulkEntryDto.php` — carry the new fields.
- **Modify** `src/Controller/Tracking/SaveEntryAction.php` (`populateEntry`), `src/Controller/Tracking/BulkEntryAction.php` (the bypass write path).
- **Modify** `src/Mcp/Tool/LogTimeTool.php` — agent dual-write (agent + delegated human).
- **Modify** `src/Repository/EntryRepository.php` (+ `OptimizedEntryRepository.php`) — source-aware reads.
- **Modify** consumers: `TimeBalanceService`, `Personio/AttendanceExportService`, `DaySummaryService`, `Tracking/DayClassService`, `EntrySummaryService`, the six `GroupBy*Action`, `ExportService`.

---

## Task 1: `EntrySource` enum

**Files:**
- Create: `src/Enum/EntrySource.php`
- Test: `tests/Enum/EntrySourceTest.php`

**Interfaces:**
- Produces: `enum EntrySource: string { case HUMAN='human'; case AGENT='agent'; }`, `EntrySource::fromString(string): EntrySource`, `EntrySource::Valid(string): bool`, `->label(): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Enum;
use App\Enum\EntrySource;
use PHPUnit\Framework\TestCase;
final class EntrySourceTest extends TestCase
{
    public function testCasesAndLabels(): void
    {
        self::assertSame('human', EntrySource::HUMAN->value);
        self::assertSame('agent', EntrySource::AGENT->value);
        self::assertSame('Human', EntrySource::HUMAN->label());
    }
    public function testValidRejectsUnknown(): void
    {
        self::assertTrue(EntrySource::Valid('agent'));
        self::assertFalse(EntrySource::Valid('robot'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`… bin/phpunit tests/Enum/EntrySourceTest.php` → class not found)

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace App\Enum;

enum EntrySource: string
{
    case HUMAN = 'human';
    case AGENT = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::HUMAN => 'Human',
            self::AGENT => 'Agent',
        };
    }

    public static function Valid(string $value): bool
    {
        return null !== self::tryFrom($value);
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Run all four gates on the two files.
- [ ] **Step 5: Commit** `feat(entry): add EntrySource enum (human|agent)`

---

## Task 2: Entry entity — source, logged_by, estimated, responsible, touchpoints

**Files:**
- Modify: `src/Entity/Entry.php` (mirror the `class`/`EntryClass` mapping at `Entry.php:99`; add near it)
- Test: `tests/Entity/EntrySourceFieldsTest.php`

**Interfaces:**
- Produces on `Entry`: `getSource(): EntrySource` / `setSource(EntrySource): static` (default `HUMAN`); `getLoggedBy(): ?User` / `setLoggedBy(?User): static`; `isEstimated(): bool` / `setEstimated(bool): static` (default `false`); `getResponsibleUser(): ?User` / `setResponsibleUser(?User): static`; `getTouchpoints(): ?array` / `setTouchpoints(?array): static` (shape `array{prompts?:int,reviews?:int,interventions?:int}`). `toArray()` gains `source` (string) + `estimated` (bool).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Entity;
use App\Entity\Entry;
use App\Enum\EntrySource;
use PHPUnit\Framework\TestCase;
final class EntrySourceFieldsTest extends TestCase
{
    public function testDefaultsAreHumanNonEstimated(): void
    {
        $entry = new Entry();
        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertFalse($entry->isEstimated());
        self::assertNull($entry->getResponsibleUser());
        self::assertSame('human', $entry->toArray()['source']);
    }
    public function testAgentAttribution(): void
    {
        $entry = (new Entry())->setSource(EntrySource::AGENT)->setEstimated(true)
            ->setTouchpoints(['prompts' => 7, 'reviews' => 2]);
        self::assertSame('agent', $entry->toArray()['source']);
        self::assertTrue($entry->toArray()['estimated']);
        self::assertSame(7, $entry->getTouchpoints()['prompts']);
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (methods missing).

- [ ] **Step 3: Implement.** Add after the `class` field (`Entry.php:99`):

```php
    #[ORM\Column(name: 'source', type: 'string', length: 8, enumType: EntrySource::class, options: ['default' => 'human'])]
    protected EntrySource $source = EntrySource::HUMAN;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'logged_by_id', referencedColumnName: 'id', nullable: true)]
    protected ?User $loggedBy = null;

    #[ORM\Column(name: 'estimated', type: 'boolean', options: ['default' => false])]
    protected bool $estimated = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'responsible_user_id', referencedColumnName: 'id', nullable: true)]
    protected ?User $responsibleUser = null;

    /** @var array{prompts?: int, reviews?: int, interventions?: int}|null */
    #[ORM\Column(name: 'touchpoints', type: 'json', nullable: true)]
    protected ?array $touchpoints = null;
```

Add `use App\Enum\EntrySource;`. Add the getters/setters (typed, `static` returns, mirroring existing setters). In `toArray()` (`Entry.php:435`) add `'source' => $this->source->value, 'estimated' => $this->estimated,`.

- [ ] **Step 4: Run — expect PASS.** Run `bin/phpstan analyze src/Entity/Entry.php` (L10) — the `touchpoints` array shape must satisfy it.
- [ ] **Step 5: Commit** `feat(entry): source/logged_by/estimated/responsible/touchpoints fields`

---

## Task 3: Migration + base schema

**Files:**
- Create: `migrations/Version20260711_EntrySourceAttribution.php`
- Modify: `sql/full.sql` (the `entries` DDL at `sql/full.sql:240-269`)

- [ ] **Step 1: Write the migration**

```php
<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711_EntrySourceAttribution extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-025: agent vs human time attribution on entries';
    }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE entries
            ADD source VARCHAR(8) DEFAULT 'human' NOT NULL,
            ADD logged_by_id INT DEFAULT NULL,
            ADD estimated TINYINT(1) DEFAULT 0 NOT NULL,
            ADD responsible_user_id INT DEFAULT NULL,
            ADD touchpoints JSON DEFAULT NULL");
        $this->addSql('ALTER TABLE entries
            ADD CONSTRAINT FK_entries_logged_by FOREIGN KEY (logged_by_id) REFERENCES users (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_entries_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_entries_source ON entries (source)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entries DROP FOREIGN KEY FK_entries_logged_by, DROP FOREIGN KEY FK_entries_responsible');
        $this->addSql('DROP INDEX IDX_entries_source ON entries');
        $this->addSql('ALTER TABLE entries DROP source, DROP logged_by_id, DROP estimated, DROP responsible_user_id, DROP touchpoints');
    }
}
```

Confirm the `users` table name + `id` column against `sql/full.sql` before finalising the FK targets.

- [ ] **Step 2: Add the same 5 columns to `sql/full.sql` entries DDL** (with the FKs/index) so a fresh install matches.
- [ ] **Step 3: Run** `… doctrine:migrations:migrate -n` against the unittest DB, then `doctrine:migrations:migrate -n prev` (down) — both clean. Then `doctrine:schema:validate` for the entries mapping (ignore the ~58 pre-existing unrelated drifts).
- [ ] **Step 4: Commit** `feat(entry): migration for source attribution columns`

---

## Task 4: `EntrySaveDto` + `BulkEntryDto` carry the new fields

**Files:**
- Modify: `src/Dto/EntrySaveDto.php`, `src/Dto/BulkEntryDto.php`
- Test: covered by Task 5/6 action tests.

**Interfaces:**
- Produces: `EntrySaveDto` gains optional `?string $source = null`, `?bool $estimated = null`, `array{prompts?:int,reviews?:int,interventions?:int}|null $touchpoints = null`. **NO `responsibleUserId` and NO `loggedBy` on the DTO** — both are derived server-side from the authenticated token owner (see Task 5); a client `responsibleUserId` would be an IDOR.

- [ ] **Step 1:** Add the three optional properties to `EntrySaveDto` (constructor-promoted, defaults null) with `#[Assert\Choice(['human','agent'])]` on `source`. `BulkEntryDto` gets nothing (bulk day-break rows are always human, server-set).
- [ ] **Step 2: Run** `bin/phpstan analyze` on both DTOs.
- [ ] **Step 3: Commit** `feat(entry): DTO fields for source attribution`

---

## Task 5: `SaveEntryAction::populateEntry` sets attribution (human-default)

**Files:**
- Modify: `src/Controller/Tracking/SaveEntryAction.php:236` (`populateEntry`), around `:245`.
- Test: `tests/Controller/Tracking/SaveEntryActionSourceTest.php`

**Interfaces:**
- Consumes: `EntrySaveDto` (Task 4), `EntrySource` (Task 1), `App\Security\ApiToken\ApiAccessToken` (ADR-021), `Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface`.
- Produces: a saved `Entry` whose `source` is `AGENT` **only** when the request is API-token-authenticated AND asks for agent (else `HUMAN`), `loggedBy = $user`, `responsibleUser = $user` for agent entries, `estimated`/`touchpoints` honoured only for agent source. **No client `responsibleUserId`.**

- [ ] **Step 1: Write the failing functional tests** (security-critical). The gate is the **auth channel**, not the source value — in the API-token channel the agent legitimately logs BOTH a `source=agent` and a delegated `source=human, estimated=true` entry:
  - session POST, no `source` → `EntrySource::HUMAN`, `estimated=false`, `touchpoints=null`, `getLoggedBy()`=caller, `getResponsibleUser()`=null.
  - **session POST with `source=agent` + `estimated=true` (spoof attempt) → still `EntrySource::HUMAN`, `estimated=false`, `touchpoints=null`** (body ignored outside the token channel — a human cannot escape attendance).
  - API-token POST `source=agent`, `estimated=true`, `touchpoints` → `EntrySource::AGENT`, `estimated=true`, touchpoints set, `getResponsibleUser()`=the token owner.
  - API-token POST `source=human`, `estimated=true`, `touchpoints` (the delegated human entry) → `EntrySource::HUMAN` **but `estimated=true` + touchpoints honoured**, `getResponsibleUser()`=the token owner.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** in `populateEntry`, after `$entry->setUser($user)` (`:245`). Inject `TokenStorageInterface $tokenStorage` + `use App\Security\ApiToken\ApiAccessToken;`:

```php
// SECURITY (ADR-025 §4): agent attribution is trusted ONLY in the API-token
// channel. In it the agent logs BOTH streams (source=agent walltime AND the
// delegated source=human estimate), so `estimated`/`touchpoints`/`source` are
// honoured for either source there, and the token owner is the responsible user.
// A session request is ALWAYS a plain human self-log — body source/estimated
// ignored, so a person cannot mark work agent and drop it from attendance/ArbZG.
// The responsible user is the token owner, never a client-supplied id (IDOR).
$isAgentChannel = $this->tokenStorage->getToken() instanceof ApiAccessToken;
if ($isAgentChannel) {
    $source = (null !== $entrySaveDto->source && EntrySource::Valid($entrySaveDto->source))
        ? EntrySource::from($entrySaveDto->source)
        : EntrySource::HUMAN;
    $entry->setSource($source)
        ->setLoggedBy($user)
        ->setResponsibleUser($user)
        ->setEstimated($entrySaveDto->estimated ?? false)
        ->setTouchpoints($entrySaveDto->touchpoints);
} else {
    $entry->setSource(EntrySource::HUMAN)
        ->setLoggedBy($user)
        ->setEstimated(false)
        ->setTouchpoints(null);
}
```

No `responsibleUserId` lookup (dropped in Task 4 — it was an IDOR).

- [ ] **Step 4: Run — expect PASS.** Four gates.
- [ ] **Step 5: Commit** `feat(entry): persist source attribution in SaveEntryAction`

---

## Task 6: `BulkEntryAction` (the SaveEntryAction bypass) sets source

**Files:**
- Modify: `src/Controller/Tracking/BulkEntryAction.php:287-295` (direct `new Entry()` build).
- Test: `tests/Controller/Tracking/BulkEntrySourceTest.php`

- [ ] **Step 1: Write the failing test** — bulk-create asserts every produced entry `getSource() === EntrySource::HUMAN` (default; the day-break bulk path is human) and `getLoggedBy()` is the caller.
- [ ] **Step 2: Run — expect FAIL** (source is default-from-entity but loggedBy is null; assert loggedBy).
- [ ] **Step 3: Implement** — in the `new Entry()` chain (`:287`) add `->setLoggedBy($user)` (source stays the entity default `HUMAN`; `estimated` stays false). This closes the "missed write path" leak.
- [ ] **Step 4: Run — expect PASS.** Four gates.
- [ ] **Step 5: Commit** `fix(entry): stamp loggedBy/source on the bulk write path`

---

## Task 7: MCP `log_time` — agent dual-write (agent walltime + delegated human estimate)

**Files:**
- Modify: `src/Mcp/Tool/LogTimeTool.php:78-115`
- Test: `tests/Mcp/Tool/LogTimeToolSourceTest.php`

**Interfaces:**
- Consumes: `ScopeGuard::requireScope('entries:write'): User` (the responsible user), `SaveEntryAction::__invoke`.
- Produces: when called with agent attribution, TWO `SaveEntryAction` invocations — one `source=agent` (wall-clock, `estimated=false`), one `source=human` (`estimated=true`, touchpoints) — both `responsibleUser` = the PAT user.

- [ ] **Step 1: Write the failing test** — call `logTime` with new args `agentWalltimeMinutes`, `humanMinutes`, `touchpoints`; assert two entries persist: a `source=agent` one with the walltime and a `source=human, estimated=true` one with `humanMinutes`, both `responsibleUser` = the token user. Backward compat: called WITHOUT the agent args → a single `source=human, estimated=false` entry (today's behaviour).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** — add optional `#[McpToolArgument]` params (`agentWalltimeMinutes: ?int`, `humanMinutes: ?int`, `touchpoints: ?array`). When `agentWalltimeMinutes` is present: build+invoke a `source=agent` `EntrySaveDto` (duration = walltime, `estimated=false`) and a `source=human` `EntrySaveDto` (duration = `humanMinutes`, `estimated=true`, `touchpoints`). **No responsible id is passed** — the tool runs under the agent's PAT (an `ApiAccessToken`), so Task 5 sets `responsibleUser` = the token owner and honours `source`/`estimated`/`touchpoints` for both entries automatically. When absent: unchanged single human write. Keep both invocations in one tool call so the pair is atomic to the agent.
- [ ] **Step 4: Run — expect PASS.** Four gates.
- [ ] **Step 5: Commit** `feat(mcp): log_time dual-writes agent walltime + delegated human estimate`

---

## Task 8: `EntryRepository` — source-aware read filtering

**Files:**
- Modify: `src/Repository/EntryRepository.php` (`findByDay:1448`, `getWorkByUser:1002`, and the SUM sites) + `src/Repository/OptimizedEntryRepository.php` twins.
- Test: `tests/Repository/EntryRepositorySourceTest.php`

**Interfaces:**
- Produces: `findByDay(int $userId, string $day, ?EntrySource $source = null): array` (null = all, back-compat); `getWorkByUser(int $userId, Period $period, ?EntrySource $source = null): array`. New DQL predicate `AND e.source = :source` applied only when `$source !== null`.

- [ ] **Step 1: Write the failing test** — seed a human (60 min) + agent (180 min) entry on the same day/user; assert `findByDay(u, day)` returns 2, `findByDay(u, day, EntrySource::HUMAN)` returns 1 (the 60-min), and `getWorkByUser(u, DAY, EntrySource::HUMAN)` sums 60 not 240.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** — add the optional `?EntrySource $source` param + conditional predicate to `findByDay` and `getWorkByUser` (and their `OptimizedEntryRepository` twins). Verify via DI which repository impl is live so the dead twin isn't the only one fixed.
- [ ] **Step 4: Run — expect PASS.** Four gates.
- [ ] **Step 5: Commit** `feat(entry): source-aware findByDay/getWorkByUser`

---

## Task 9: `TimeBalanceService` — IST from human only (capacity / ArbZG-adjacent)

**Files:** Modify `src/Service/TimeBalanceService.php:70-72`. Test `tests/Service/TimeBalanceServiceSourceTest.php`.

- [ ] **Step 1: Failing test** — a user with a human 8h + an agent 8h on one day has IST = 8h (not 16h), `over/behind` computed off 8h.
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** pass `EntrySource::HUMAN` to the three `getWorkByUser(...)` calls.
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `fix(balance): time-balance IST counts human source only`

---

## Task 10: Personio export input — human only (the ArbZG legal boundary)

**Files:** Modify `src/Service/Personio/AttendanceExportService.php:126-127`. Test extends `tests/Service/Personio/AttendanceExportServiceTest.php`.

- [ ] **Step 1: Failing test** — a day with a human interval + an agent interval projects/export only the human interval (agent wall-clock never becomes a Personio WORK period).
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** change the call to `entryRepository->findByDay((int) $user->getId(), $day->format('Y-m-d'), EntrySource::HUMAN)`.
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `fix(personio): export only human-source attendance`

---

## Task 11: `DaySummaryService` + `DayClassService` — human day shape

**Files:** Modify `src/Service/DaySummaryService.php:50` (total), `src/Service/Tracking/DayClassService.php:47` (per-day iteration). Tests alongside each.

- [ ] **Step 1: Failing tests** — day total (`DaySummaryService`) excludes agent minutes; `DayClassService` classifies PAUSE/OVERLAP over human entries only (an agent entry interleaved does not create a phantom human OVERLAP).
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** `DaySummaryService` — filter `findByDay(..., EntrySource::HUMAN)` for the human total (keep an agent total separately if the UI needs it — see Task 14). `DayClassService::recalculate` — iterate human entries for the human day classification.
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `fix(day): day total and day-class use human source`

---

## Task 12: `getEntrySummary` (+ Optimized twin) — source-aware "Info" totals

**Files:** Modify `src/Repository/EntryRepository.php:1311-1415` + `src/Repository/OptimizedEntryRepository.php:144-190`. Test `tests/Repository/EntrySummarySourceTest.php`.

- [ ] **Step 1: Failing test** — the per-customer/project/activity/ticket totals returned by `getEntrySummary` count human source only (this feeds the `log_time` response the agent itself reads, so it must not double-count the agent's own writes).
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** add `AND e.source = 'human'` to the SUM/CASE aggregations in both the base and Optimized implementations (verify which is live via DI).
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `fix(entry): entry-summary totals count human source only`

---

## Task 13: Controlling / interpretation — slice by source, never sum across

**Files:** Modify `src/Repository/EntryRepository.php:382` (`getRawData` SELECT), `src/Service/ExportService.php:103` (`buildEntryRow`), and the six `GroupBy*Action` (`GroupByProjectAction.php:66`, `GroupByCustomerAction.php:66`, `GroupByActivityAction.php:67`, `GroupByUserAction.php:70`, `GroupByTicketAction.php:59`, `GroupByWorktimeAction.php:91`) + their base `BaseInterpretationController.php:40`. Test `tests/Controller/Interpretation/GroupBySourceTest.php`.

- [ ] **Step 1: Failing test** — an interpretation over a dataset with human+agent entries exposes a `source` (and `responsible`) axis; the human rollup and agent rollup are separate keys, and no bucket sums human+agent together.
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** add `e.source` (+ `responsible_user_id`) to `getRawData`'s SELECT and to `ExportService::buildEntryRow`'s output columns; in `BaseInterpretationController::$sum` / the six actions, group the rollup by `source` (add it to the bucket key) so controlling can report human vs agent hours side by side (ADR §7). Keep the default report human-first; agent hours are a distinct column, never folded in.
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `feat(controlling): source/responsible as report axes, human/agent never summed`

---

## Task 14: v2 API reads expose the split

**Files:** Modify `src/Controller/Api/V2/GetTimeBalanceAction.php:33` path (via Task 9 it's already human), `src/Service/DaySummaryService.php` DTO. Test `tests/Controller/Api/V2/DaySummarySourceTest.php`.

- [ ] **Step 1: Failing test** — `GET /api/v2/day` returns `humanMinutes` and `agentMinutes` separately (not one merged `total`); `GET /api/v2/time-balance` IST reflects human only.
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** extend the day-summary response with `agentMinutes` (from `findByDay(..., EntrySource::AGENT)`) alongside the human total; time-balance already human-only after Task 9. Add `source`/`estimated` to per-entry serialisation (already in `toArray()` from Task 2 — confirm it surfaces).
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `feat(api): v2 day/time-balance expose human vs agent split`

---

## Task 15: Overlap validation — source-aware guard (documented no-op today)

**Files:** Modify `src/Repository/EntryRepository.php:780` (`findOverlappingEntries`). Test `tests/Repository/OverlapSourceTest.php`.

**Note:** `findOverlappingEntries` currently has **zero callers** — no hard non-overlap invariant is enforced today (only `end > start`). This task makes the *query* source-aware so that IF a human non-overlap rule is later wired into `SaveEntryAction`, agent entries are already exempt. It does not add a new rejection (YAGNI) — it prevents a future one from counting agent overlaps.

- [ ] **Step 1: Failing test** — `findOverlappingEntries` with an overlapping agent entry present returns only human overlaps (agent entries never count as a human double-booking).
- [ ] **Step 2: FAIL.**
- [ ] **Step 3:** add `AND e.source = 'human'` to the `findOverlappingEntries` predicate (`:790`).
- [ ] **Step 4: PASS.** Gates.
- [ ] **Step 5: Commit** `feat(entry): overlap query is human-source only`

---

## Self-Review

**Spec coverage (ADR-025 §):** §1 source dimension → Tasks 1-3. §2 agent logs both + logged_by → Tasks 2,5,7. §3 estimated + touchpoints from facts → Tasks 2,7. §4 responsible attribution → Tasks 2,5,7 (from ScopeGuard/PAT user). §5 ArbZG/attendance human-only → Tasks 9,10,11 (+ the "estimated stays correctable" override path = Task 7's UpdateEntry route, already present via `UpdateEntryAction`). §6 overlap exempts agent → Task 15. §7 controlling slices by source → Tasks 13,14. Consequences "every consumer audited" → Tasks 8-14 cover the full seam-map gate; the 5 highest-risk leak points (getWorkByUser, Personio findByDay, getEntrySummary+twin, BulkEntryAction, GroupBy/export) are each an explicit task.

**Placeholder scan:** no "handle edge cases"/TBD; every code step shows the exact change or code. The repetitive consumer tasks list each `file:line` with its concrete predicate change.

**Type consistency:** `EntrySource` (Task 1) is used identically in entity (Task 2), DTO validation (Task 4), actions (5,6), tool (7), repository params (8), and consumers (9-15). `findByDay`/`getWorkByUser` gain the same `?EntrySource $source = null` signature everywhere.

**Open verification (resolve as the FIRST implementation step, before Task 7):** the exact agent→TT ingest payload for touchpoints/human-minutes (ADR §Verification 1-2) — confirm the MCP `log_time` argument names and whether the agent supplies `humanMinutes` directly or TT derives it from turn timestamps it receives. If the latter, Task 7 gains a small deriver; the schema (Tasks 1-6) is unaffected either way, so Tasks 1-6 can proceed immediately.
