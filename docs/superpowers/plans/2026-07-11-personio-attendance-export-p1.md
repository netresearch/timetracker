# Personio Attendance Export (ADR-024 P1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Export each opted-in user's TimeTracker worklogs to Personio as daily work attendances (ADR-024 §3): a `tt:export-personio-attendances` cron command, admin-managed encrypted config, and a per-user Settings opt-in.

**Architecture:** A Personio provider layer under `src/Service/Personio/` (`PersonioClient` for the v2 client-credentials API, a pure `AttendanceProjector`, and an `AttendanceExportService`) reusing the ADR-023 audit/run infrastructure (`SyncRun`/`SyncRunItem` + `AbstractSyncRunService`, the admin-CRUD and Settings patterns). The Jira reconciliation engine is not touched.

**API facts (verified 2026-07-10 against developer.personio.de):** Personio v2 authenticates with company `client_credentials` only (no per-employee delegation). `/v2/attendance-periods` supports POST/GET/PATCH/DELETE. There is **no break-minutes field** — breaks are the gaps between `WORK`-type periods (Personio derives the break in its day view). So a day projects to **one WORK period per worked segment**; a day maps to a *set* of Personio period ids. This is the API-native realization of ADR-024 §3's "one block + break sum" (same day-view outcome, resolves ADR verification points 1 & 3).

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 + Migrations, Guzzle 7, PHPUnit 13, PHPStan level 10, Rector; SolidJS/bun for the two UI touches (admin config field, Settings toggle).

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
- Container commands via `docker compose --profile dev exec app-dev`. Backend gates before EVERY commit, all four: `composer analyze` (PHPStan L10), `composer analyze:arch` (phpat), `composer rector` (apply, then re-run cs-fix + tests), `composer cs-fix`.
- **Functional/integration tests hit the `.env.test.local` dev-DB trap** — run with `docker compose --profile dev exec -T -e 'DATABASE_URL=mysql://unittest:unittest@db_unittest:3306/unittest?serverVersion=mariadb-12.1.2&charset=utf8mb4' app-dev php bin/phpunit <path>`. Pure unit tests (no DB) run normally.
- Frontend inside `frontend/`: `bun run typecheck && bun run test <file> && bun run lint`. New user strings are Paraglide `m.*()` keys in ALL FIVE `messages/{en,de,es,fr,ru}.json` (identical key sets; natural German). No `useMutation` (write = `postJson`/`postForm` + `invalidateQueries`), no Tailwind utilities, axe-clean, WCAG 2.2 AA.
- Entities extend `App\Model\Base`, protected typed properties, fluent setters returning `static`, `datetime_immutable` for timestamps.
- **Every schema change is mirrored in `sql/full.sql`.** `sql/unittest/001_testtables.sql` is generated (`make prepare-test-sql`) — never hand-edit; run `make reset-test-db` after schema changes (or ALTER `db_unittest` directly for local test runs).
- `TokenEncryptionService::encryptToken(string): string` / `decryptToken(string): string` (AES-256-GCM; `''`/`'0'` → `''`). The Personio client secret is stored encrypted (ADR-024 §2 — stricter than the plaintext Jira precedent, deliberately).
- `Contract` weekday hours: **`hours_0 = Sunday … hours_6 = Saturday`**; resolve via `App\Service\Util\ContractHoursResolver::weekdayHours(?Contract, int $weekday)` (P2 only — not P1).
- Commits conventional, `git commit -S --signoff`, no AI attribution. NEVER stage `config/reference.php`.

---

### Task 1: Schema foundation — enums, users columns, config + state tables

**Files:**
- Modify: `src/Enum/SyncRunType.php`, `src/Entity/User.php`
- Create: `src/Entity/PersonioConfig.php`, `src/Entity/PersonioAttendanceExport.php`, `src/Repository/PersonioConfigRepository.php`, `src/Repository/PersonioAttendanceExportRepository.php`, `migrations/Version20260711_PersonioAttendanceExport.php`
- Modify: `sql/full.sql`
- Test: `tests/Enum/SyncEnumsTest.php` (extend), `tests/Entity/PersonioConfigTest.php`, `tests/Entity/PersonioAttendanceExportTest.php`

**Interfaces (Produces):**
- `SyncRunType::PERSONIO_EXPORT = 'personio_export'`, `SyncRunType::PERSONIO_IMPORT = 'personio_import'` (fits the `length:16` column: 15 chars each).
- `User::getPersonioSyncEnabled(): bool` / `setPersonioSyncEnabled(bool): static`; `User::getPersonioEmployeeId(): ?int` / `setPersonioEmployeeId(?int): static`.
- `PersonioConfig` (table `personio_configs`): `id`, `name` (string 63, unique), `baseUrl` (string 255), `clientId` (string 255), `clientSecret` (text — encrypted at rest), `absenceProject` (`ManyToOne Project`, `absence_project_id` nullable, onDelete SET NULL), `active` (bool default true). Fluent getters/setters. `public const array SECRET_KEYS = ['clientSecret'];` + `toSafeArray(): array` (= `toArray()` minus SECRET_KEYS).
- `PersonioAttendanceExport` (table `personio_attendance_export`): `id`, `user` (`ManyToOne User`, not null, onDelete CASCADE), `day` (date), `periodIds` (json — `list<string>` of Personio period ids TT created, in interval order), `basePayload` (json — the last-sent `list<array{start:int,end:int}>`), `lastExportedAt` (datetime_immutable), `lastSyncRun` (`ManyToOne SyncRun`, nullable, onDelete SET NULL). `UNIQUE (user_id, day)`, index on `user_id`.
- `PersonioConfigRepository::findActive(): ?PersonioConfig` (the one active config, or null); `findOneByName(string): ?PersonioConfig`.
- `PersonioAttendanceExportRepository::findOneByUserAndDay(User $user, DateTimeInterface $day): ?PersonioAttendanceExport`.

- [ ] **Step 1: Extend the enum test (failing first)**

In `tests/Enum/SyncEnumsTest.php`, update the `SyncRunType` values assertion to include the two new cases at the end:
```php
public function testSyncRunTypeValues(): void
{
    self::assertSame(
        ['import', 'sync', 'verify', 'personio_export', 'personio_import'],
        array_column(SyncRunType::cases(), 'value'),
    );
}
```
Run: `docker compose --profile dev exec app-dev php bin/phpunit tests/Enum/SyncEnumsTest.php` — Expected: FAIL (arrays differ).

- [ ] **Step 2: Add the enum cases**

In `src/Enum/SyncRunType.php`, after `case VERIFY = 'verify';`:
```php
    case PERSONIO_EXPORT = 'personio_export';
    case PERSONIO_IMPORT = 'personio_import';
```
Re-run the enum test — Expected: PASS.

- [ ] **Step 3: Add the `users` columns**

In `src/Entity/User.php`, add two properties (match the file's attribute style; near the other prefs) and fluent accessors:
```php
#[ORM\Column(name: 'personio_sync_enabled', type: 'boolean', options: ['default' => false])]
protected bool $personioSyncEnabled = false;

#[ORM\Column(name: 'personio_employee_id', type: 'bigint', nullable: true)]
protected ?int $personioEmployeeId = null;
```
```php
public function getPersonioSyncEnabled(): bool
{
    return $this->personioSyncEnabled;
}

public function setPersonioSyncEnabled(bool $personioSyncEnabled): static
{
    $this->personioSyncEnabled = $personioSyncEnabled;

    return $this;
}

public function getPersonioEmployeeId(): ?int
{
    return $this->personioEmployeeId;
}

public function setPersonioEmployeeId(?int $personioEmployeeId): static
{
    $this->personioEmployeeId = $personioEmployeeId;

    return $this;
}
```
(bigint maps to `?int` in PHP here; the values fit PHP int.)

- [ ] **Step 4: Write the failing entity tests**

`tests/Entity/PersonioConfigTest.php`:
```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\PersonioConfig;
use PHPUnit\Framework\TestCase;

final class PersonioConfigTest extends TestCase
{
    public function testFluentSettersAndDefaults(): void
    {
        $config = new PersonioConfig();

        self::assertTrue($config->getActive());

        $config->setName('Personio')->setBaseUrl('https://api.personio.de')->setClientId('cid')->setClientSecret('enc')->setActive(false);

        self::assertSame('Personio', $config->getName());
        self::assertSame('https://api.personio.de', $config->getBaseUrl());
        self::assertSame('cid', $config->getClientId());
        self::assertSame('enc', $config->getClientSecret());
        self::assertFalse($config->getActive());
    }

    public function testToSafeArrayStripsClientSecret(): void
    {
        $config = new PersonioConfig();
        $config->setName('Personio')->setClientId('cid')->setClientSecret('enc');

        $safe = $config->toSafeArray();

        self::assertArrayHasKey('name', $safe);
        self::assertArrayNotHasKey('clientSecret', $safe);
        self::assertArrayNotHasKey('client_secret', $safe);
    }
}
```

`tests/Entity/PersonioAttendanceExportTest.php`:
```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\PersonioAttendanceExport;
use App\Entity\User;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PersonioAttendanceExportTest extends TestCase
{
    public function testFluentSetters(): void
    {
        $user = new User();
        $export = new PersonioAttendanceExport();
        $export->setUser($user)
            ->setDay(new DateTime('2026-07-01'))
            ->setPeriodIds(['1001', '1002'])
            ->setBasePayload([['start' => 100, 'end' => 200]])
            ->setLastExportedAt(new DateTimeImmutable('2026-07-01 12:00:00'));

        self::assertSame($user, $export->getUser());
        self::assertSame(['1001', '1002'], $export->getPeriodIds());
        self::assertSame([['start' => 100, 'end' => 200]], $export->getBasePayload());
    }
}
```

Add both files to the `unit` testsuite entity `<file>` list in `phpunit.xml.dist` (alphabetically among the existing `<file>tests/Entity/...</file>` lines).

- [ ] **Step 5: Implement the two entities**

`src/Entity/PersonioConfig.php` — model attributes on `src/Entity/TicketSystem.php` (SECRET_KEYS + toSafeArray). `clientSecret` is `type: 'text'` (holds base64 ciphertext). Include `absenceProject` FK now (used in P2). Full class:
```php
namespace App\Entity;

use App\Model\Base;
use App\Repository\PersonioConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Company-level Personio API configuration (ADR-024). One active row in practice;
 * the client secret is stored encrypted (TokenEncryptionService) and stripped from
 * API responses via SECRET_KEYS/toSafeArray.
 */
#[ORM\Entity(repositoryClass: PersonioConfigRepository::class)]
#[ORM\Table(name: 'personio_configs')]
class PersonioConfig extends Base
{
    public const array SECRET_KEYS = ['clientSecret'];

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 63, unique: true)]
    protected string $name = '';

    #[ORM\Column(name: 'base_url', type: 'string', length: 255)]
    protected string $baseUrl = '';

    #[ORM\Column(name: 'client_id', type: 'string', length: 255)]
    protected string $clientId = '';

    #[ORM\Column(name: 'client_secret', type: 'text')]
    protected string $clientSecret = '';

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'absence_project_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Project $absenceProject = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    protected bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(string $clientSecret): static
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    public function getAbsenceProject(): ?Project
    {
        return $this->absenceProject;
    }

    public function setAbsenceProject(?Project $absenceProject): static
    {
        $this->absenceProject = $absenceProject;

        return $this;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        foreach (self::SECRET_KEYS as $key) {
            unset($data[$key]);
            unset($data[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key))]);
        }

        return $data;
    }
}
```

`src/Entity/PersonioAttendanceExport.php`:
```php
namespace App\Entity;

use App\Model\Base;
use App\Repository\PersonioAttendanceExportRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per (user, day) record of the Personio attendance periods TT created (ADR-024 §3):
 * the TT-owned period ids and the last-sent projection, so the export updates only
 * its own records idempotently.
 */
#[ORM\Entity(repositoryClass: PersonioAttendanceExportRepository::class)]
#[ORM\Table(name: 'personio_attendance_export')]
#[ORM\UniqueConstraint(name: 'uniq_personio_export_user_day', columns: ['user_id', 'day'])]
#[ORM\Index(name: 'idx_personio_export_user', columns: ['user_id'])]
class PersonioAttendanceExport extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?User $user = null;

    #[ORM\Column(type: 'date')]
    protected ?DateTimeInterface $day = null;

    /** @var list<string> */
    #[ORM\Column(name: 'period_ids', type: 'json')]
    protected array $periodIds = [];

    /** @var list<array{start: int, end: int}> */
    #[ORM\Column(name: 'base_payload', type: 'json')]
    protected array $basePayload = [];

    #[ORM\Column(name: 'last_exported_at', type: 'datetime_immutable')]
    protected ?DateTimeImmutable $lastExportedAt = null;

    #[ORM\ManyToOne(targetEntity: SyncRun::class)]
    #[ORM\JoinColumn(name: 'last_sync_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?SyncRun $lastSyncRun = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getDay(): ?DateTimeInterface
    {
        return $this->day;
    }

    public function setDay(DateTimeInterface $day): static
    {
        $this->day = $day;

        return $this;
    }

    /** @return list<string> */
    public function getPeriodIds(): array
    {
        return $this->periodIds;
    }

    /** @param list<string> $periodIds */
    public function setPeriodIds(array $periodIds): static
    {
        $this->periodIds = $periodIds;

        return $this;
    }

    /** @return list<array{start: int, end: int}> */
    public function getBasePayload(): array
    {
        return $this->basePayload;
    }

    /** @param list<array{start: int, end: int}> $basePayload */
    public function setBasePayload(array $basePayload): static
    {
        $this->basePayload = $basePayload;

        return $this;
    }

    public function getLastExportedAt(): ?DateTimeImmutable
    {
        return $this->lastExportedAt;
    }

    public function setLastExportedAt(DateTimeImmutable $lastExportedAt): static
    {
        $this->lastExportedAt = $lastExportedAt;

        return $this;
    }

    public function getLastSyncRun(): ?SyncRun
    {
        return $this->lastSyncRun;
    }

    public function setLastSyncRun(?SyncRun $lastSyncRun): static
    {
        $this->lastSyncRun = $lastSyncRun;

        return $this;
    }
}
```

- [ ] **Step 6: Implement the repositories**

`src/Repository/PersonioConfigRepository.php`:
```php
namespace App\Repository;

use App\Entity\PersonioConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonioConfig>
 */
class PersonioConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonioConfig::class);
    }

    public function findActive(): ?PersonioConfig
    {
        return $this->findOneBy(['active' => true]);
    }

    public function findOneByName(string $name): ?PersonioConfig
    {
        return $this->findOneBy(['name' => $name]);
    }
}
```

`src/Repository/PersonioAttendanceExportRepository.php`:
```php
namespace App\Repository;

use App\Entity\PersonioAttendanceExport;
use App\Entity\User;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonioAttendanceExport>
 */
class PersonioAttendanceExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonioAttendanceExport::class);
    }

    public function findOneByUserAndDay(User $user, DateTimeInterface $day): ?PersonioAttendanceExport
    {
        return $this->findOneBy(['user' => $user, 'day' => $day->format('Y-m-d')]);
    }
}
```

- [ ] **Step 7: Write the migration**

`migrations/Version20260711_PersonioAttendanceExport.php` (style of `Version20260710_WorklogSyncOptIn.php`):
```php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711_PersonioAttendanceExport extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-024 P1: personio_configs + personio_attendance_export tables, users personio opt-in/employee-id columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD personio_sync_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD personio_employee_id BIGINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE personio_configs (
                id INT AUTO_INCREMENT NOT NULL,
                absence_project_id INT DEFAULT NULL,
                name VARCHAR(63) NOT NULL,
                base_url VARCHAR(255) NOT NULL,
                client_id VARCHAR(255) NOT NULL,
                client_secret LONGTEXT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE INDEX uniq_personio_configs_name (name),
                INDEX idx_personio_configs_absence_project (absence_project_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_personio_configs_project FOREIGN KEY (absence_project_id) REFERENCES projects (id) ON DELETE SET NULL
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE personio_attendance_export (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                last_sync_run_id INT DEFAULT NULL,
                day DATE NOT NULL,
                period_ids JSON NOT NULL,
                base_payload JSON NOT NULL,
                last_exported_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_personio_export_user_day (user_id, day),
                INDEX idx_personio_export_user (user_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_personio_export_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_personio_export_run FOREIGN KEY (last_sync_run_id) REFERENCES sync_runs (id) ON DELETE SET NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personio_attendance_export');
        $this->addSql('DROP TABLE personio_configs');
        $this->addSql('ALTER TABLE users DROP COLUMN personio_sync_enabled, DROP COLUMN personio_employee_id');
    }
}
```

Mirror all three DDL changes into `sql/full.sql` (the `users` columns in the `users` CREATE TABLE; the two new CREATE TABLE blocks near the other feature tables — after `sync_runs` so the FK target exists). Then regenerate + reset the test DB:
```bash
docker compose --profile dev exec app-dev sh -c "sed '1s/^/USE unittest;\n/' sql/full.sql > sql/unittest/001_testtables.sql"
docker compose --profile dev exec -T db_unittest sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS unittest; CREATE DATABASE unittest;"'
for f in sql/unittest/000_testdatabase.sql sql/unittest/001_testtables.sql sql/unittest/002_testdata.sql; do docker compose --profile dev exec -T db_unittest sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD"' < "$f"; done
git checkout sql/unittest/001_testtables.sql   # it is git-ignored/generated — don't commit it
```

- [ ] **Step 8: Run migration on the dev DB + validate**

```bash
docker compose --profile dev exec app-dev php bin/console doctrine:migrations:migrate --no-interaction
docker compose --profile dev exec app-dev php bin/console doctrine:schema:validate
```
Expected: migration applies; mapping validates (DB-in-sync drift is pre-existing — confirm no `personio_*` entries in `doctrine:schema:update --dump-sql`; if a new index is flagged for drop, add the matching `#[ORM\Index]`).

- [ ] **Step 9: Run the tests**

```bash
docker compose --profile dev exec app-dev php bin/phpunit tests/Enum/SyncEnumsTest.php tests/Entity/PersonioConfigTest.php tests/Entity/PersonioAttendanceExportTest.php
```
Expected: PASS.

- [ ] **Step 10: Quality gates and commit**

```bash
docker compose --profile dev exec app-dev composer analyze && docker compose --profile dev exec app-dev composer analyze:arch
docker compose --profile dev exec app-dev composer rector && docker compose --profile dev exec app-dev composer cs-fix
docker compose --profile dev exec app-dev composer test:unit
git add src/Enum/SyncRunType.php src/Entity/User.php src/Entity/PersonioConfig.php src/Entity/PersonioAttendanceExport.php src/Repository/PersonioConfigRepository.php src/Repository/PersonioAttendanceExportRepository.php migrations/Version20260711_PersonioAttendanceExport.php sql/full.sql tests/Enum/SyncEnumsTest.php tests/Entity/PersonioConfigTest.php tests/Entity/PersonioAttendanceExportTest.php phpunit.xml.dist
git commit -S --signoff -m "feat(personio): schema foundation for attendance export (ADR-024 P1)"
```

---

### Task 2: PersonioClient — v2 API with client-credentials auth

**Files:**
- Create: `src/Service/Personio/PersonioClient.php`, `src/Service/Personio/PersonioClientFactory.php`, `src/DTO/Personio/AttendancePeriod.php`, `src/Exception/Personio/PersonioApiException.php`
- Test: `tests/Service/Personio/PersonioClientTest.php`

**Interfaces (Produces):**
- `AttendancePeriod` readonly DTO: `(?string $id, string $personId, string $type, string $startDateTime, ?string $endDateTime, ?string $status, ?string $comment)` with `fromApiResponse(object): self` parsing `id`, `person->id`, `type`, `start->date_time`, `end->date_time`, `status`, `comment`. `isApproved(): bool` → `'CONFIRMED' === $this->status`.
- `PersonioApiException extends RuntimeException` — `getStatusCode(): int`. Thrown on non-2xx; a 403/409 on an approved period is still a `PersonioApiException` the caller inspects via status.
- `PersonioClient` (constructed for one `PersonioConfig` with its **decrypted** secret):
  - `listAttendancePeriods(string $personId, DateTimeImmutable $from, DateTimeImmutable $to): array` → `list<AttendancePeriod>` (paginates the `cursor` param; `person.id`, `start.date_time.gte/lte` filters).
  - `createAttendancePeriod(string $personId, string $type, string $startIso, string $endIso, ?string $comment = null): string` — POST, returns the new period id.
  - `updateAttendancePeriod(string $periodId, string $startIso, string $endIso, ?string $comment = null): void` — PATCH.
  - `deleteAttendancePeriod(string $periodId): void` — DELETE.
  - `listPersons(): array` → `list<array{id: string, first_name: ?string, last_name: ?string, email: ?string}>` (paginated; for P3 matching, added now for reuse).
  - Bearer token from `POST {baseUrl}/v2/auth/token` with `grant_type=client_credentials&client_id&client_secret`; cached in-instance with a 60s expiry skew (mirror `JiraCloudApiService::getValidAccessToken`). On HTTP 429, back off honoring `Retry-After` (or 1s) up to 3 attempts.
  - `protected createHttpClient(array $config): Client { return new Client($config); }` — the test seam (repo convention, per `JiraCloudApiService`).
- `PersonioClientFactory::create(PersonioConfig $config): PersonioClient` — decrypts `config.getClientSecret()` via `TokenEncryptionService` and builds the client. Constructor: `(TokenEncryptionService $tokenEncryptionService)`.

- [ ] **Step 1: Failing test** — use the anonymous-subclass `createHttpClient` seam (as `JiraCloudApiServiceTest` does). Provide a stub Guzzle `Client` returning canned `Response`s: first the token response `{"access_token":"tok","expires_in":3600}`, then per-endpoint bodies. Cases:
  - `testCreateAttendancePeriodPostsAndReturnsId` — asserts a POST to `v2/attendance-periods` with body `{person:{id},type:'WORK',start:{date_time},end:{date_time}}` and returns the parsed id.
  - `testListAttendancePeriodsParsesAndPaginates` — two pages via `cursor`, returns all periods as `AttendancePeriod`.
  - `testUpdateAndDeleteHitCorrectPaths` — PATCH `v2/attendance-periods/{id}`, DELETE same.
  - `testTokenFetchedOnceAndReused` — the token endpoint is called once across two API calls.
  - `testNon2xxThrowsPersonioApiExceptionWithStatus` — a 403 body → `PersonioApiException` with `getStatusCode() === 403`.
  - `testRetriesOn429ThenSucceeds` — first attempt 429, second 200.

  (Adapt the exact stub wiring to how `JiraCloudApiServiceTest::routeClient` records configs + returns responses; the intent is canned per-path responses and a throwing branch for non-2xx/429.)

- [ ] **Step 2: Implement** `AttendancePeriod`, `PersonioApiException`, `PersonioClient`, `PersonioClientFactory`. The date-time fields are sent as `{"date_time": "<ISO8601 with offset>"}` objects (per the verified payload). Use `guzzlehttp/guzzle` (already a direct dep). Keep methods ≤15 complexity.

- [ ] **Step 3: Pass, gates, commit** — `feat(personio): v2 API client with client-credentials auth (ADR-024 P1)`.

---

### Task 3: AttendanceProjector — day entries → work intervals

**Files:**
- Create: `src/Service/Personio/AttendanceProjector.php`, `src/ValueObject/Personio/WorkInterval.php`
- Test: `tests/Service/Personio/AttendanceProjectorTest.php`

**Interfaces (Produces):**
- `WorkInterval` readonly: `(int $startTimestamp, int $endTimestamp)` with `toArray(): array{start:int,end:int}`, `equals(self): bool`.
- `AttendanceProjector::project(array $entries): array` — `list<Entry> → list<WorkInterval>`. Builds each entry's `[start,end]` from `entry.getDay()` + `entry.getStart()`/`getEnd()` (server TZ, same idiom as `EntryWorklogProjector`), sorts by start, **merges overlapping or touching intervals** (next.start ≤ current.end → extend current.end to max). Zero/invalid-duration entries (end ≤ start) are skipped. Returns overlap-free worked segments in order; the gaps between them are the breaks Personio derives. An empty input → `[]`.

- [ ] **Step 1: Failing test** — table-driven (`Entry` stubs with `getDay`/`getStart`/`getEnd`):
  - single entry 09:00–10:00 → one interval.
  - two contiguous 09:00–12:30, 13:00–17:30 (30-min gap) → two intervals (break preserved).
  - overlapping 09:00–11:00, 10:30–12:00 → one merged interval 09:00–12:00 (no double count).
  - touching 09:00–10:00, 10:00–11:00 → one merged 09:00–11:00.
  - out-of-order input → sorted output.
  - empty → `[]`.
  Assert on `array_map(fn (WorkInterval $i) => $i->toArray(), $result)` with expected timestamps built from `DateTime`.

- [ ] **Step 2: Implement** the projector + VO (pure, no dependencies).

- [ ] **Step 3: Pass, gates, commit** — `feat(personio): attendance projector merging entries into work intervals (ADR-024 §3)`.

---

### Task 4: AttendanceExportService — reconcile + write, TT-owned records

**Files:**
- Create: `src/Service/Personio/AttendanceExportService.php`
- Test: `tests/Service/Personio/AttendanceExportServiceTest.php`

**Interfaces (Produces):**
- `AttendanceExportService extends AbstractSyncRunService`. Constructor: `(EntityManagerInterface, EntryRepository, PersonioAttendanceExportRepository, PersonioConfigRepository, PersonioClientFactory, AttendanceProjector, ClockInterface, ?LoggerInterface $logger = null)`.
- `exportUser(User $user, DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): SyncRun` — one `SyncRun` (type `PERSONIO_EXPORT`, `triggeredBy = $user`, scope `{from,to,dry_run}`). Requires an active `PersonioConfig` (else run FAILED with an ERROR item) and `$user->getPersonioEmployeeId()` (else FAILED item `'no Personio employee id mapped'`). Builds the client once via the factory. For each day in `[from,to]`:
  - `entries = entryRepository->findByDay((int) $user->getId(), $day)`; `intervals = projector->project($entries)`.
  - `state = exportRepo->findOneByUserAndDay($user, $day)`.
  - **reconcile the TT-owned period set** (positional zip of `state.periodIds` with `intervals`):
    - both present at index i → if the interval changed vs `state.basePayload[i]` → `PATCH` period i (dry-run: counter `would_update`); counter `updated`/`in_sync`.
    - interval without a stored id → `POST` new WORK period (dry-run: `would_create`); collect its id; counter `created`.
    - stored id without an interval → `DELETE` it (dry-run: `would_delete`); counter `deleted`.
    - no intervals and no state → nothing.
  - each Personio write wrapped in try/catch: a `PersonioApiException` whose status is 403/409 (approved-period rejection) → park a `CONFLICT` item (`reason: 'attendance approved in Personio; not modified'`, keep the stored id); any other → `ERROR` item + counter `errors`; the day continues, the run continues.
  - After a successful non-dry write set for the day: upsert `state` (`periodIds` = the resulting id list, `basePayload` = `array_map(toArray, intervals)`, `lastExportedAt = now`, `lastSyncRun = run`); when the day ends empty and a state existed, `remove($state)`.
  - Dry-run performs no Personio writes and no state writes.
- `exportAllOptedIn(DateTimeImmutable $from, DateTimeImmutable $to, bool $dryRun = false): array` → `list<SyncRun>` — iterate users with `personio_sync_enabled = true` AND a non-null `personio_employee_id` (add `UserRepository::findPersonioExportEnabled(): list<User>`), calling `exportUser` for each.
- Uses `executeRun` for lifecycle/EM-closure hardening; NEVER dispatches `EntryEvent` (read-only on the TT side — it only reads entries).

- [ ] **Step 1: Failing tests** — mock the client (via a mocked `PersonioClientFactory` returning a mocked `PersonioClient`), `EntryRepository`, `PersonioAttendanceExportRepository`, `PersonioConfigRepository`, real `AttendanceProjector`, `MockClock`. Cases:
  - `testExportsNewDayCreatesPeriodsAndState` — a day with two intervals, no state → two `createAttendancePeriod` calls, a persisted state with both ids, counter `created` = 2.
  - `testUnchangedDaySkips` — state matches projection → no client writes, counter `in_sync`.
  - `testChangedIntervalPatches` — one interval time changed → one `updateAttendancePeriod`, counter `updated`.
  - `testEmptiedDayDeletesPeriodsAndState` — entries gone, state had one id → `deleteAttendancePeriod` called, state removed, counter `deleted`.
  - `testApprovedRejectionParksConflict` — `updateAttendancePeriod` throws `PersonioApiException(403)` → CONFLICT item, stored id retained, run COMPLETED.
  - `testNoEmployeeIdFailsRun`, `testNoActiveConfigFailsRun`.
  - `testDryRunPerformsNoWrites` — client write methods `expects(never())`; counters `would_*` populated.
  - `testExportAllOptedInIteratesEnabledUsers`.

- [ ] **Step 2: Implement.** Keep the per-day reconcile in a private method ≤15 complexity; bundle run state in a tiny context if needed.

- [ ] **Step 3: Pass (`test:unit`), gates, commit** — `feat(personio): attendance export service reconciling TT-owned periods (ADR-024 §3)`.

---

### Task 5: `tt:export-personio-attendances` command

**Files:**
- Create: `src/Command/TtExportPersonioAttendancesCommand.php`
- Test: `tests/Command/TtExportPersonioAttendancesCommandTest.php`

**Interfaces (Produces):**
- `tt:export-personio-attendances [--from=Y-m-d] [--to=Y-m-d] [--user=USERNAME] [--dry-run]`. Default window: `--from` = 14 days ago, `--to` = today (rolling, idempotent). With `--user`, exports that one user (error if unknown or not opted-in/mapped); without, `exportAllOptedIn(...)`. Renders each run via `SyncRunConsoleRenderer` (label `'Personio export'`). Exit 1 if any run FAILED. Reject blank/whitespace dates (try/catch on `DateTimeImmutable`).

- [ ] **Step 1: Failing tests** — mock `AttendanceExportService` + `ManagerRegistry` + real `SyncRunConsoleRenderer` (as `TtSyncWorklogsCommandTest` does): `testExportsAllByDefault`, `testExportsSingleUserWithUserOption`, `testUnknownUserFails`, `testInvalidDateFails`, `testFailedRunExitsNonZero`, `testDefaultWindowIsRollingFourteenDays`.
- [ ] **Step 2: Implement** (invokable `#[AsCommand(name: 'tt:export-personio-attendances', ...)]`, mirror `TtSyncWorklogsCommand`).
- [ ] **Step 3: Pass, smoke `bin/console list tt`, gates, commit** — `feat(personio): tt:export-personio-attendances command (ADR-024 P1)`.

---

### Task 6: Admin config CRUD (backend + frontend)

**Files:**
- Create: `src/Controller/Admin/SavePersonioConfigAction.php`, `src/Controller/Admin/GetPersonioConfigsAction.php`, `src/Controller/Admin/DeletePersonioConfigAction.php`, `src/Dto/PersonioConfigSaveDto.php`
- Modify: `frontend/src/admin/entities.ts` (new `personio` descriptor), `frontend/messages/{en,de,es,fr,ru}.json`
- Test: `tests/Controller/Admin/PersonioConfigCrudTest.php`, extend the admin frontend test

**Interfaces (Produces):**
- `POST /personio-config/save` (`#[IsGranted('ROLE_ADMIN')]`, `#[MapRequestPayload] PersonioConfigSaveDto`): find-or-new by id; duplicate-name → 406; **encrypt `clientSecret` via `TokenEncryptionService` before persist**, and **preserve on blank** (blank submit keeps the stored encrypted secret — snapshot before mapping, restore after, mirroring `SaveTicketSystemAction::captureStoredSecrets/restoreBlankSecrets`). `absenceProjectId` resolved to a `Project` (unknown id → 422). Response `$config->toSafeArray()`.
- `GET /getPersonioConfigs` (`#[RequireScope('ticketsystems:read')]` + `#[IsGranted('ROLE_ADMIN')]`): list, each stripped of `PersonioConfig::SECRET_KEYS`.
- `DELETE /personio-config/delete` (or POST with id): remove by id.
- `PersonioConfigSaveDto` (`final readonly`, `Assert` on name/baseUrl/clientId; `clientSecret` optional): fields `id, name, baseUrl, clientId, clientSecret, absenceProjectId, active`.
- Frontend `personio` descriptor in `entities.ts`: `listEndpoint: '/getPersonioConfigs'`, `saveEndpoint: '/personio-config/save'`, `deleteEndpoint: '/personio-config/delete'`, fields `name` (text, required), `baseUrl` (text), `clientId` (text), `clientSecret` (text — opens blank, keep-on-blank), `absence_project` (`select`, `source: 'projects'`), `active` (checkbox). `toForm` opens `clientSecret` blank; `toPayload` renames `absence_project` → `absenceProjectId` (0 → null). Add the entity to `adminEntities()`.

- [ ] **Step 1: Failing functional test** — `PersonioConfigCrudTest` (extends `Tests\AbstractWebTestCase`, `logInSession('unittest')`): `testSavePersistsAndEncryptsSecret` (POST creates a row; the stored `client_secret` column is NOT the plaintext — decrypts back via `TokenEncryptionService`), `testSaveBlankSecretKeepsStored` (second save with `clientSecret: ''` keeps the first secret), `testListStripsSecret` (GET response has no `client_secret`), `testDeleteRemoves`.
- [ ] **Step 2: Implement** the three actions + DTO (copy the `SaveTicketSystemAction` structure), then the frontend descriptor + i18n keys (`admin_e_personio`, field labels — all five catalogs).
- [ ] **Step 3: Pass (controller test via DB override + `bun run typecheck/test/lint`), gates, commit** — `feat(personio): admin config CRUD with encrypted secret (ADR-024 §2)`.

---

### Task 7: Settings opt-in toggle

**Files:**
- Modify: `src/Controller/Settings/SaveSettingsAction.php`, `frontend/src/pages/Settings.tsx`, `frontend/src/config.ts` (`AppConfig`), `templates/ui/index.html.twig` (the `APP_CONFIG` payload), `frontend/messages/{en,de,es,fr,ru}.json`
- Test: extend `tests/Controller/Settings/SaveSettingsActionTest.php` (or the existing settings test), `frontend/src/pages/Settings.test.tsx`

**Interfaces (Produces):**
- `SaveSettingsAction` sets `$user->setPersonioSyncEnabled((bool) $request->request->get('personio_sync_enabled'))` alongside the existing prefs.
- `AppConfig` gains `personioSyncEnabled: boolean`; the Twig `APP_CONFIG` JSON includes it (from `user.getPersonioSyncEnabled()`).
- `Settings.tsx`: a `BOOL_SETTINGS` entry `{ name: 'personio_sync_enabled', label: () => m.settings_personio_sync(), help: () => m.settings_personio_sync_help(), initial: (c: AppConfig) => c.personioSyncEnabled }`, included in the `/settings/save` `postForm` body. i18n keys in all five catalogs (natural German: "Meine Arbeitszeiten an Personio übertragen").

- [ ] **Step 1: Failing tests** — backend: POST `/settings/save` with `personio_sync_enabled=1` sets the user flag (reload asserts true; omitted → false). Frontend: the Settings checkbox renders and is included in the save payload (extend the existing settings test; assert `postForm` receives `personio_sync_enabled`).
- [ ] **Step 2: Implement** the four touches + i18n.
- [ ] **Step 3: Pass (backend via DB override + full frontend gate), commit** — `feat(personio): per-user attendance export opt-in in settings (ADR-024 §1)`.

---

### Task 8: ADR verification points + operator docs

**Files:**
- Modify: `docs/adr/ADR-024-personio-attendance-absence-sync.md`
- Create: `docs/personio-sync.md`

- [ ] **Step 1: Resolve ADR verification points** — mark points 1 & 3 resolved (v2 `/attendance-periods` full CRUD incl. PATCH; breaks are gaps between WORK periods, so a day = a set of WORK periods, not one block-with-break; §3's write rule reconciles the period set positionally). Note point 5 (rate limits): auth 150 req/min documented, attendance endpoints unspecified — the client backs off on 429. Update Status to `Accepted — P1 (attendance export) implemented; P2 (absence import) + P3 (auto-match/API) pending`; update the README index row.
- [ ] **Step 2: `docs/personio-sync.md`** — operator guide: admin creates the Personio config (base URL + client id/secret from a Personio API credential, absence project), users opt in via Settings, employee-id mapping (manual for P1; auto-match is P3), the cron `docker exec timetracker php bin/console tt:export-personio-attendances` (rolling 14-day window, `--dry-run` to preview), what parks as conflicts (approved attendances), and that TT only touches its own periods.
- [ ] **Step 3: Commit** — `docs(adr): ADR-024 P1 implemented; Personio operator guide`.

---

## Phase boundary (orientation — NOT this plan)

- **P2 — Absence import:** `personio_absence_import` state, `/v2/absence-periods` + `/v2/absence-types` reads, `AbsenceImportService` (contract-hours entries via `ContractHoursResolver`, `Activity` name match, `personio_configs.absence_project_id`, cancellation-aware deletes), `tt:import-personio-absences`.
- **P3 — polish:** admin auto-match action (`/v2/persons` → `personio_employee_id`), optional v2 API/MCP triggers, run-history UI coverage.

## Self-review notes

- Secret handling: unlike the plaintext Jira `oauth2_client_secret`, this plan **encrypts** the Personio client secret at rest (ADR-024 §2) — Task 1 stores it `type:'text'`, Task 6 encrypts on save + decrypts in `PersonioClientFactory`.
- `SyncRunType` column is `length:16`; `personio_export`/`personio_import` are 15 chars — fit, verified.
- The projector output (`list<WorkInterval>`) and the export's `basePayload` (`list<array{start:int,end:int}>`) are the same shape via `WorkInterval::toArray()` — Task 3 and Task 4 agree.
- `hours_0 = Sunday` is a P2 concern (absence duration), not used in P1 export.
