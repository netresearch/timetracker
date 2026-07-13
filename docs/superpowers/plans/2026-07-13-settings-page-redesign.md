# Settings Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the user settings modal into a full page at `/settings/:section?` with five deep-linkable sections, backed by new `GET`/`PATCH /api/v2/settings` endpoints and a reusable `HelpPopover` component.

**Architecture:** Three independently mergeable phases: (1) v2 settings API with PATCH partial-update semantics, (2) frontend section split + routing conversion + legacy endpoint removal, (3) contextual help. Spec: `docs/superpowers/specs/2026-07-13-settings-page-redesign-design.md`.

**Tech Stack:** Symfony 8 / PHP 8.5 / PHPStan level 10 (backend), SolidJS 1.9 + @solidjs/router 0.16 + Ark UI + Paraglide i18n + Vitest (frontend), Playwright (e2e).

## Global Constraints

- PHP: `declare(strict_types=1)`, PER-CS + Symfony style, `final` classes, thin controllers, PHPDoc on public APIs.
- Solid 1.9, write 2.0-ready: no `use:` directives, no `<Index>`, no `classList` (use `class`).
- i18n: every new user-facing string in ALL five catalogs `frontend/messages/{en,de,es,fr,ru}.json`; never edit `frontend/src/paraglide/` by hand; use `import { m } from '../paraglide/messages.js'`.
- Frontend package manager is **bun**; backend commands run via `docker compose --profile dev exec app-dev ...` when a container is required.
- A11y: WCAG 2.2 AA + documented AAA subset (7:1 contrast in both schemes, 44px targets, keyboard reachable).
- Commits: conventional format, signed: `git commit -S --signoff -m "..."`. No AI attribution.
- Quality gates before each phase's PR: `composer analyze && composer cs-check && composer test` (backend) and `bun run lint && bun run typecheck && bun run test` (in `frontend/`).
- Every v2 controller MUST carry `#[RequireScope('...')]` — a fail-closed coverage test rejects unannotated token-reachable endpoints. `settings` is already in `ApiScope::RESOURCES` (`src/ValueObject/ApiScope.php:28-31`), so `settings:read`/`settings:write` are already valid scopes.
- Work on a feature branch `feat/settings-page-redesign` in a new worktree per repo convention (never `git checkout` in an existing folder). First commit on the branch: the spec + this plan (`docs: add settings page redesign spec and plan`).

---

## Phase 1 — Backend: `GET`/`PATCH /api/v2/settings`

### Task 1: Response DTO + `GET /api/v2/settings`

**Files:**
- Create: `src/Dto/Response/UserSettingsDto.php`
- Create: `src/Controller/Api/V2/GetSettingsAction.php`
- Test: `tests/Controller/Api/V2/SettingsActionsTest.php`

**Interfaces:**
- Consumes: `App\Entity\User` getters (`getLocale()`, `getShowEmptyLine()`, `getSuggestTime()`, `getShowFuture()`, `getMinEntryDuration()`, `getPersonioSyncEnabled()` — verify exact getter names against `src/Entity/User.php` before writing; `SaveSettingsAction` at `src/Controller/Settings/SaveSettingsAction.php:51-58` shows the setter side).
- Produces: wire shape consumed by Task 2 and by the frontend (Task 4):
  `{ "locale": "de", "show_empty_line": bool, "suggest_time": bool, "show_future": bool, "min_entry_duration": int, "personio_sync_enabled": bool }`

- [ ] **Step 1: Write the failing test**

Model on `tests/Controller/Api/V2/GetTimeBalanceActionTest.php` (base class `Tests\AbstractWebTestCase`, trait `Tests\Traits\MintsApiTokens`).

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\MintsApiTokens;

use function json_decode;

/**
 * GET + PATCH /api/v2/settings: session and PAT access, scope gates,
 * partial-update semantics, and the DTO wire shape.
 *
 * @internal
 */
final class SettingsActionsTest extends AbstractWebTestCase
{
    use MintsApiTokens;

    private const array KEYS = [
        'locale', 'show_empty_line', 'suggest_time', 'show_future',
        'min_entry_duration', 'personio_sync_enabled',
    ];

    public function testSessionGetReturnsSettingsShape(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $this->assertStatusCode(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        foreach (self::KEYS as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertIsString($data['locale']);
        self::assertIsInt($data['min_entry_duration']);
    }

    public function testTokenWithSettingsReadIsAuthorized(): void
    {
        $status = $this->requestWithToken('/api/v2/settings', $this->mintToken(['settings:read']));

        self::assertSame(200, $status);
    }

    public function testTokenWithoutSettingsReadIsForbidden(): void
    {
        $status = $this->requestWithToken('/api/v2/settings', $this->mintToken(['entries:read']));

        self::assertSame(403, $status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/phpunit tests/Controller/Api/V2/SettingsActionsTest.php`
Expected: FAIL — 404 for `/api/v2/settings` (route does not exist yet).

- [ ] **Step 3: Write the response DTO**

`src/Dto/Response/UserSettingsDto.php` (model on `src/Dto/Response/WorklogSyncPreferenceDto.php` for the `fromEntity`/`fromUser` factory style):

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\User;

/**
 * Wire shape of GET/PATCH /api/v2/settings: the authenticated user's
 * account settings (the fields the settings page's Account and Sync
 * sections read and write).
 */
final readonly class UserSettingsDto
{
    public function __construct(
        public string $locale,
        public bool $show_empty_line,
        public bool $suggest_time,
        public bool $show_future,
        public int $min_entry_duration,
        public bool $personio_sync_enabled,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            locale: $user->getLocale(),
            show_empty_line: $user->getShowEmptyLine(),
            suggest_time: $user->getSuggestTime(),
            show_future: $user->getShowFuture(),
            min_entry_duration: $user->getMinEntryDuration(),
            personio_sync_enabled: $user->getPersonioSyncEnabled(),
        );
    }
}
```

Verify the six getter names against `src/Entity/User.php` (they may be `isShowEmptyLine()` etc.) and adjust the factory — not the wire keys.

- [ ] **Step 4: Write the GET controller**

`src/Controller/Api/V2/GetSettingsAction.php` (model on `UpdateWorklogSyncPreferencesAction.php` for structure):

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\UserSettingsDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The authenticated user's account settings (settings page, Account +
 * Sync sections). Self only — there is no per-user variant.
 */
final readonly class GetSettingsAction
{
    #[RequireScope('settings:read')]
    #[Route(path: '/api/v2/settings', name: 'api_v2_settings_get', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(UserSettingsDto::fromUser($user));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `bin/phpunit tests/Controller/Api/V2/SettingsActionsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Quality gates + commit**

Run: `composer analyze && composer cs-check && composer test`
Expected: clean (fix any PHPStan level-10 findings before committing).

```bash
git add src/Dto/Response/UserSettingsDto.php src/Controller/Api/V2/GetSettingsAction.php tests/Controller/Api/V2/SettingsActionsTest.php
git commit -S --signoff -m "feat(api): add GET /api/v2/settings for the authenticated user"
```

### Task 2: Request DTO + `PATCH /api/v2/settings` (partial update)

**Files:**
- Create: `src/Dto/UpdateUserSettingsDto.php`
- Create: `src/Controller/Api/V2/UpdateSettingsAction.php`
- Modify: `tests/Controller/Api/V2/SettingsActionsTest.php` (add PATCH tests)

**Interfaces:**
- Consumes: `UserSettingsDto::fromUser()` (Task 1), `App\Service\Util\LocalizationService::normalizeLocale(string): string` (used the same way in `SaveSettingsAction.php:57`), `User` setters (`setShowEmptyLine`, `setSuggestTime`, `setShowFuture`, `setMinEntryDuration`, `setPersonioSyncEnabled`, `setLocale`).
- Produces: `PATCH /api/v2/settings` accepting any subset of the six wire keys; **absent (null) fields stay unchanged**; responds with the full updated `UserSettingsDto`.

- [ ] **Step 1: Write the failing tests (append to SettingsActionsTest)**

```php
    public function testPatchSingleFieldLeavesOthersUntouched(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $before = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($before);

        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', [
            'suggest_time' => !$before['suggest_time'],
        ]);
        $this->assertStatusCode(200);

        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($after);
        self::assertSame(!$before['suggest_time'], $after['suggest_time']);
        // Every other field is untouched — the partial-update guarantee.
        foreach (['locale', 'show_empty_line', 'show_future', 'min_entry_duration', 'personio_sync_enabled'] as $key) {
            self::assertSame($before[$key], $after[$key], $key);
        }

        // Restore for test isolation (shared fixture user).
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', [
            'suggest_time' => $before['suggest_time'],
        ]);
        $this->assertStatusCode(200);
    }

    public function testPatchFullPayloadPersistsAllFields(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $before = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($before);

        $payload = [
            'locale' => 'en',
            'show_empty_line' => true,
            'suggest_time' => false,
            'show_future' => true,
            'min_entry_duration' => 15,
            'personio_sync_enabled' => false,
        ];
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', $payload);
        $this->assertStatusCode(200);

        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($after);
        self::assertSame($payload, $after);

        // Restore.
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', $before);
        $this->assertStatusCode(200);
    }

    public function testPatchNormalizesLocale(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/settings');
        $before = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($before);

        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', ['locale' => 'xx']);
        $this->assertStatusCode(200);
        $after = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($after);
        // Unknown locales normalize to a supported one — never persisted raw.
        self::assertContains($after['locale'], ['en', 'de', 'es', 'fr', 'ru']);

        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', ['locale' => $before['locale']]);
        $this->assertStatusCode(200);
    }

    public function testPatchMinDurationOutOfRangeIsRejected(): void
    {
        $this->client->jsonRequest(Request::METHOD_PATCH, '/api/v2/settings', ['min_entry_duration' => 100000]);
        $this->assertStatusCode(422);
    }

    public function testTokenWithSettingsWriteMayPatch(): void
    {
        $status = $this->requestWithToken(
            '/api/v2/settings',
            $this->mintToken(['settings:read', 'settings:write']),
            method: Request::METHOD_PATCH,
            payload: [],
        );

        self::assertSame(200, $status);
    }

    public function testTokenWithoutSettingsWriteMayNotPatch(): void
    {
        $status = $this->requestWithToken(
            '/api/v2/settings',
            $this->mintToken(['settings:read']),
            method: Request::METHOD_PATCH,
            payload: [],
        );

        self::assertSame(403, $status);
    }
```

Check the actual signature of `MintsApiTokens::requestWithToken()` first (`tests/Traits/MintsApiTokens.php`) — if it does not take method/payload parameters, extend the trait or issue the token request via `$this->client->jsonRequest` with the `Authorization: Bearer` header instead; keep the assertion pairs (200 with `settings:write`, 403 without) as written.

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `bin/phpunit tests/Controller/Api/V2/SettingsActionsTest.php`
Expected: Task-1 tests PASS, new PATCH tests FAIL with 405/404 (no PATCH route).

- [ ] **Step 3: Write the request DTO**

`src/Dto/UpdateUserSettingsDto.php` — all-nullable is the PATCH contract (`null` = "not sent = unchanged", the same convention `WorklogSyncPreferencesDto::$sync_all` already uses):

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body of PATCH /api/v2/settings. Every field is optional;
 * an absent (null) field leaves the stored value unchanged — that
 * partial semantics is what lets the settings page's sections save
 * independently (spec §6).
 */
final readonly class UpdateUserSettingsDto
{
    public function __construct(
        public ?string $locale = null,
        public ?bool $show_empty_line = null,
        public ?bool $suggest_time = null,
        public ?bool $show_future = null,
        #[Assert\Range(min: 0, max: 1440)]
        public ?int $min_entry_duration = null,
        public ?bool $personio_sync_enabled = null,
    ) {
    }
}
```

- [ ] **Step 4: Write the PATCH controller**

`src/Controller/Api/V2/UpdateSettingsAction.php`:

```php
<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\UserSettingsDto;
use App\Dto\UpdateUserSettingsDto;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\Util\LocalizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Partial update of the authenticated user's account settings: only the
 * fields present in the payload are persisted ("not sent = unchanged").
 * This server-side guarantee replaces the old client-side preservation
 * logic for the disabled Personio opt-in (spec §6/§9).
 */
final readonly class UpdateSettingsAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LocalizationService $localizationService,
    ) {
    }

    #[RequireScope('settings:write')]
    #[Route(path: '/api/v2/settings', name: 'api_v2_settings_update', methods: ['PATCH'])]
    public function __invoke(#[MapRequestPayload] UpdateUserSettingsDto $dto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (null !== $dto->show_empty_line) {
            $user->setShowEmptyLine($dto->show_empty_line);
        }

        if (null !== $dto->suggest_time) {
            $user->setSuggestTime($dto->suggest_time);
        }

        if (null !== $dto->show_future) {
            $user->setShowFuture($dto->show_future);
        }

        if (null !== $dto->min_entry_duration) {
            $user->setMinEntryDuration($dto->min_entry_duration);
        }

        if (null !== $dto->personio_sync_enabled) {
            $user->setPersonioSyncEnabled($dto->personio_sync_enabled);
        }

        if (null !== $dto->locale) {
            $user->setLocale($this->localizationService->normalizeLocale($dto->locale));
        }

        $this->entityManager->flush();

        return new JsonResponse(UserSettingsDto::fromUser($user));
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `bin/phpunit tests/Controller/Api/V2/SettingsActionsTest.php`
Expected: PASS (9 tests).

- [ ] **Step 6: Quality gates + commit**

Run: `composer analyze && composer cs-check && composer test`
Expected: clean. (PHPStan runs on the tests too — run it AFTER the tests exist.)

```bash
git add src/Dto/UpdateUserSettingsDto.php src/Controller/Api/V2/UpdateSettingsAction.php tests/Controller/Api/V2/SettingsActionsTest.php
git commit -S --signoff -m "feat(api): add PATCH /api/v2/settings with partial-update semantics"
```

Phase 1 is now independently mergeable (legacy `/settings/save` untouched). Open its PR before starting Phase 2, or continue on the branch — either way CI must be green here.

---

## Phase 2 — Frontend: section split, routing, legacy removal

### Task 3: `patchJson` client helper

**Files:**
- Modify: `frontend/src/api/client.ts:126-176`

**Interfaces:**
- Produces: `patchJson<T>(path: string, payload: Record<string, unknown>): Promise<T>` — same `#[MapRequestPayload]` JSON contract and `ApiError` handling as `postJson`/`putJson`.

- [ ] **Step 1: Extend sendJson and add patchJson**

In `frontend/src/api/client.ts`, change the `sendJson` method union and add the export next to `putJson` (after line 143):

```ts
/**
 * PATCHes a typed JSON body — partial updates on the v2 endpoints
 * ("not sent = unchanged", e.g. /api/v2/settings). Same #[MapRequestPayload]
 * binding and error contract as postJson.
 */
export function patchJson<T = unknown>(
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  return sendJson<T>('PATCH', path, payload)
}
```

and

```ts
async function sendJson<T>(
  method: 'POST' | 'PUT' | 'PATCH',
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
```

- [ ] **Step 2: Verify**

Run (in `frontend/`): `bun run typecheck && bun run lint`
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/api/client.ts
git commit -S --signoff -m "feat(frontend): add patchJson API client helper"
```

### Task 4: `frontend/src/api/settings.ts` wrapper

**Files:**
- Create: `frontend/src/api/settings.ts`

**Interfaces:**
- Consumes: `getJson`, `patchJson` (Task 3); the Task-1/2 wire shape.
- Produces: `UserSettings` type, `fetchSettings(): Promise<UserSettings>`, `patchSettings(patch: Partial<UserSettings>): Promise<UserSettings>` — used by Tasks 5 and 7.

- [ ] **Step 1: Write the wrapper**

```ts
import { getJson, patchJson } from './client'

/** Wire shape of GET/PATCH /api/v2/settings (UserSettingsDto). */
export interface UserSettings {
  locale: string
  show_empty_line: boolean
  suggest_time: boolean
  show_future: boolean
  min_entry_duration: number
  personio_sync_enabled: boolean
}

export function fetchSettings(): Promise<UserSettings> {
  return getJson<UserSettings>('/api/v2/settings')
}

/** Partial update: absent fields stay unchanged (server-guaranteed). */
export function patchSettings(patch: Partial<UserSettings>): Promise<UserSettings> {
  return patchJson<UserSettings>('/api/v2/settings', patch)
}
```

- [ ] **Step 2: Verify + commit**

Run: `bun run typecheck && bun run lint`

```bash
git add frontend/src/api/settings.ts
git commit -S --signoff -m "feat(frontend): add v2 settings API wrapper"
```

### Task 5: `AccountSection` component

**Files:**
- Create: `frontend/src/components/settings/AccountSection.tsx`
- Create: `frontend/src/components/settings/AccountSection.test.tsx`
- Modify: `frontend/messages/{en,de,es,fr,ru}.json` (no new keys needed — reuses `settings_section_account*`, `settings_language`, `settings_show_empty_line`, `settings_suggest_time`, `settings_show_future`, `settings_min_entry_duration*`, `app_save`, `app_saving`, `settings_saved`, `settings_save_error`)

**Interfaces:**
- Consumes: `patchSettings` (Task 4), `appConfig()` (`frontend/src/config.ts`), `apiErrorMessage` (client).
- Produces: `export function AccountSection(): JSX.Element` — mounted by the Task-9 shell. Personio is deliberately NOT here anymore (moves to Task 7).

- [ ] **Step 1: Write the component**

Extraction of `frontend/src/pages/Settings.tsx:46-230` minus the Personio entry, switched from `postForm('/settings/save', …)` to `patchSettings`:

```tsx
import { createSignal, For } from 'solid-js'
import { Show } from 'solid-js'

import { apiErrorMessage } from '../../api/client'
import { patchSettings } from '../../api/settings'
import { appConfig, type AppConfig } from '../../config'
import { m } from '../../paraglide/messages.js'

// Only the locales the UI actually ships translations for (the same SET as
// project.inlang/settings.json — the display order here is deliberate and
// independent of it). Labels are endonyms on purpose — a user locked into the
// wrong language must still recognise their own.
const LANGUAGES = [
  { value: 'de', label: 'Deutsch' },
  { value: 'en', label: 'English' },
  { value: 'es', label: 'Español' },
  { value: 'fr', label: 'Français' },
  { value: 'ru', label: 'Русский' },
]

interface BoolSetting {
  name: 'show_empty_line' | 'suggest_time' | 'show_future'
  label: () => string
  initial: (c: AppConfig) => boolean
}

const BOOL_SETTINGS: BoolSetting[] = [
  { name: 'show_empty_line', label: () => m.settings_show_empty_line(), initial: (c) => c.showEmptyLine },
  { name: 'suggest_time', label: () => m.settings_suggest_time(), initial: (c) => c.suggestTime },
  { name: 'show_future', label: () => m.settings_show_future(), initial: (c) => c.showFuture },
]

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

/** Account settings — persisted server-side (PATCH /api/v2/settings),
 *  applied on every device. The only settings section with a Save button. */
export function AccountSection() {
  const config = appConfig()
  const [status, setStatus] = createSignal<Status>({ kind: 'idle' })
  const statusMessage = () => {
    const current = status()

    return current.kind === 'error' ? current.message : ''
  }

  async function onSubmit(event: SubmitEvent) {
    event.preventDefault()
    const form = event.currentTarget as HTMLFormElement
    const data = new FormData(form)
    const locale = String(data.get('locale') ?? config.locale)

    setStatus({ kind: 'saving' })
    try {
      const result = await patchSettings({
        locale,
        show_empty_line: data.get('show_empty_line') !== null,
        suggest_time: data.get('suggest_time') !== null,
        show_future: data.get('show_future') !== null,
        min_entry_duration: Number(data.get('min_entry_duration') ?? config.minEntryDuration),
      })

      // All UI strings are locale-bound at load time; a locale change needs a
      // full reload (the URL — and so the active section — is unchanged).
      if (result.locale !== config.locale) {
        window.location.reload()

        return
      }

      setStatus({ kind: 'ok' })
    } catch (error) {
      setStatus({ kind: 'error', message: apiErrorMessage(error, m.settings_save_error()) })
    }
  }

  return (
    <form class="stack-form" onSubmit={(event) => void onSubmit(event)}>
      <fieldset class="settings-group">
        <legend>{m.settings_section_account()}</legend>
        <p class="settings-section-hint">{m.settings_section_account_hint()}</p>

        <label class="field">
          <span>{m.settings_language()}</span>
          <select name="locale" value={config.locale}>
            <For each={LANGUAGES}>
              {(lang) => <option value={lang.value}>{lang.label}</option>}
            </For>
          </select>
        </label>

        <For each={BOOL_SETTINGS}>
          {(setting) => (
            <label class="field-check">
              <input type="checkbox" name={setting.name} checked={setting.initial(config)} />
              <span>{setting.label()}</span>
            </label>
          )}
        </For>

        {/* Server setting: a new entry's end pre-fills to start + this many minutes. */}
        <label class="field">
          <span>{m.settings_min_entry_duration()}</span>
          <input type="number" name="min_entry_duration" min="0" max="1440" step="5" value={config.minEntryDuration} />
          <small class="field-hint">{m.settings_min_entry_duration_hint()}</small>
        </label>
      </fieldset>

      <div class="form-actions">
        <button type="submit" class="primary-button" disabled={status().kind === 'saving'}>
          {status().kind === 'saving' ? m.app_saving() : m.app_save()}
        </button>
        <Show when={status().kind === 'ok'}>
          <span role="status" class="form-status is-ok">{m.settings_saved()}</span>
        </Show>
        <Show when={status().kind === 'error'}>
          <span role="alert" class="form-status is-error">{statusMessage()}</span>
        </Show>
      </div>
    </form>
  )
}
```

- [ ] **Step 2: Write the test**

`AccountSection.test.tsx` — mock the API module; assert the PATCH payload shape and that Personio is absent. Model rendering setup (APP_CONFIG stubbing) on the existing `frontend/src/pages/Settings.test.tsx` before deleting/adapting it in Task 9 — copy its `window.APP_CONFIG` fixture helper.

```tsx
import { render, screen, fireEvent, waitFor } from '@solidjs/testing-library'
import { describe, expect, it, vi } from 'vitest'

import { AccountSection } from './AccountSection'

const patchSettings = vi.hoisted(() => vi.fn())
vi.mock('../../api/settings', () => ({ patchSettings }))

// Reuse the APP_CONFIG fixture pattern from the previous pages/Settings.test.tsx.

describe('AccountSection', () => {
  it('saves the five account fields via PATCH and shows the ok status', async () => {
    patchSettings.mockResolvedValue({ locale: window.APP_CONFIG!.locale })
    render(() => <AccountSection />)

    fireEvent.click(screen.getByRole('button', { name: /save|speichern/i }))

    await waitFor(() => expect(patchSettings).toHaveBeenCalledOnce())
    const payload = patchSettings.mock.calls[0][0]
    expect(Object.keys(payload).sort()).toEqual([
      'locale', 'min_entry_duration', 'show_empty_line', 'show_future', 'suggest_time',
    ])
    // Personio is NOT part of this section's payload (it lives in Sync).
    expect(payload).not.toHaveProperty('personio_sync_enabled')
    expect(await screen.findByRole('status')).toBeInTheDocument()
  })

  it('reloads on a locale change', async () => {
    patchSettings.mockResolvedValue({ locale: 'fr' })
    const reload = vi.fn()
    vi.stubGlobal('location', { ...window.location, reload })
    render(() => <AccountSection />)

    fireEvent.click(screen.getByRole('button', { name: /save|speichern/i }))

    await waitFor(() => expect(reload).toHaveBeenCalled())
    vi.unstubAllGlobals()
  })
})
```

- [ ] **Step 3: Run the test**

Run: `bun run test src/components/settings/AccountSection.test.tsx`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/settings/AccountSection.tsx frontend/src/components/settings/AccountSection.test.tsx
git commit -S --signoff -m "feat(frontend): extract AccountSection saving via PATCH /api/v2/settings"
```

### Task 6: `AppearanceSection` component

**Files:**
- Create: `frontend/src/components/settings/AppearanceSection.tsx`

**Interfaces:**
- Consumes: the pref helpers exactly as `pages/Settings.tsx:8-12` imports them today (`dateFormat`, `fontPref`, `gridEditPref`, `navLayoutPref`, `isoDate`).
- Produces: `export function AppearanceSection(): JSX.Element`.

- [ ] **Step 1: Write the component**

This is a pure move of `pages/Settings.tsx` lines 16-44 (the four option-list constants), 93-112 (the signals + date-format logic) and 244-354 (the device fieldset JSX), with two changes: import paths gain one `../` level, and the section hint becomes a visible instant-apply badge:

```tsx
// After the legend, replace
//   <p class="settings-section-hint">{m.settings_section_device_hint()}</p>
// with:
<p class="settings-section-hint settings-instant-badge">{m.settings_section_device_hint()}</p>
```

Everything else (all four selects, the custom-pattern input, hints, comments) moves verbatim. Component signature:

```tsx
/** Device-local UI preferences — localStorage only, apply instantly.
 *  Nothing here is submitted; there is deliberately no Save button. */
export function AppearanceSection() {
```

wrapping the moved fieldset in `<div class="stack-form">…</div>` as today.

- [ ] **Step 2: Verify**

Run: `bun run typecheck && bun run lint`
Expected: clean (Settings.tsx still compiles — it keeps its copy until Task 9 removes it).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/settings/AppearanceSection.tsx
git commit -S --signoff -m "feat(frontend): extract AppearanceSection (device-local preferences)"
```

### Task 7: `PersonioOptIn` + `SyncSection`

**Files:**
- Create: `frontend/src/components/settings/PersonioOptIn.tsx`
- Create: `frontend/src/components/settings/PersonioOptIn.test.tsx`
- Create: `frontend/src/components/settings/SyncSection.tsx`

**Interfaces:**
- Consumes: `patchSettings` (Task 4); `WorklogImportSection`, `WorklogSyncPreferences` (existing, unchanged); existing keys `settings_personio_sync`, `settings_personio_sync_help`, `settings_personio_sync_unavailable`, `settings_saved`, `settings_save_error`.
- Produces: `export function SyncSection(): JSX.Element`.

- [ ] **Step 1: Write PersonioOptIn**

Save-on-toggle (matching the WorklogSyncPreferences interaction model — a single opt-in flag needs no Save button):

```tsx
import { createSignal, Show } from 'solid-js'

import { apiErrorMessage } from '../../api/client'
import { patchSettings } from '../../api/settings'
import { appConfig } from '../../config'
import { m } from '../../paraglide/messages.js'

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

/** Per-user Personio attendance-export opt-in (ADR-024). Saves on toggle via
 *  PATCH /api/v2/settings — sending ONLY this field, so a disabled control
 *  (Personio unconfigured) can never flip the stored value. */
export function PersonioOptIn() {
  const config = appConfig()
  const [enabled, setEnabled] = createSignal(config.personioSyncEnabled)
  const [status, setStatus] = createSignal<Status>({ kind: 'idle' })

  async function toggle(next: boolean) {
    const previous = enabled()
    setEnabled(next)
    setStatus({ kind: 'saving' })
    try {
      const result = await patchSettings({ personio_sync_enabled: next })
      setEnabled(result.personio_sync_enabled)
      setStatus({ kind: 'ok' })
    } catch (error) {
      setEnabled(previous)
      setStatus({ kind: 'error', message: apiErrorMessage(error, m.settings_save_error()) })
    }
  }

  return (
    <fieldset class="settings-group">
      <legend>{m.settings_personio_sync()}</legend>
      <label class="field-check">
        <input
          type="checkbox"
          name="personio_sync_enabled"
          checked={enabled()}
          disabled={!config.personioConfigured || status().kind === 'saving'}
          onChange={(event) => void toggle(event.currentTarget.checked)}
        />
        <span>{m.settings_personio_sync()}</span>
        <Show
          when={config.personioConfigured}
          fallback={<small class="field-hint">{m.settings_personio_sync_unavailable()}</small>}
        >
          <small class="field-hint">{m.settings_personio_sync_help()}</small>
        </Show>
      </label>
      <Show when={status().kind === 'ok'}>
        <span role="status" class="form-status is-ok">{m.settings_saved()}</span>
      </Show>
      <Show when={status().kind === 'error'}>
        <span role="alert" class="form-status is-error">{status().kind === 'error' ? (status() as { message: string }).message : ''}</span>
      </Show>
    </fieldset>
  )
}
```

- [ ] **Step 2: Write the PersonioOptIn test**

```tsx
import { render, screen, fireEvent, waitFor } from '@solidjs/testing-library'
import { describe, expect, it, vi } from 'vitest'

import { PersonioOptIn } from './PersonioOptIn'

const patchSettings = vi.hoisted(() => vi.fn())
vi.mock('../../api/settings', () => ({ patchSettings }))

describe('PersonioOptIn', () => {
  it('PATCHes only the personio field on toggle', async () => {
    // APP_CONFIG fixture: personioConfigured: true, personioSyncEnabled: false
    patchSettings.mockResolvedValue({ personio_sync_enabled: true })
    render(() => <PersonioOptIn />)

    fireEvent.click(screen.getByRole('checkbox'))

    await waitFor(() => expect(patchSettings).toHaveBeenCalledOnce())
    expect(patchSettings).toHaveBeenCalledWith({ personio_sync_enabled: true })
  })

  it('is disabled and never saves when Personio is unconfigured', () => {
    // APP_CONFIG fixture: personioConfigured: false
    render(() => <PersonioOptIn />)

    expect(screen.getByRole('checkbox')).toBeDisabled()
    expect(patchSettings).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 3: Write SyncSection**

```tsx
import { WorklogImportSection } from '../WorklogImportSection'
import { WorklogSyncPreferences } from '../WorklogSyncPreferences'
import { PersonioOptIn } from './PersonioOptIn'

/** Everything that moves data between the timetracker and external systems:
 *  Jira import (ADR-023 UC1), nightly-sync opt-ins (ADR-023 amendment),
 *  Personio attendance-export opt-in (ADR-024). */
export function SyncSection() {
  return (
    <>
      <WorklogImportSection />
      <WorklogSyncPreferences />
      <PersonioOptIn />
    </>
  )
}
```

- [ ] **Step 4: Run tests + commit**

Run: `bun run test src/components/settings/PersonioOptIn.test.tsx`
Expected: PASS.

```bash
git add frontend/src/components/settings/PersonioOptIn.tsx frontend/src/components/settings/PersonioOptIn.test.tsx frontend/src/components/settings/SyncSection.tsx
git commit -S --signoff -m "feat(frontend): add SyncSection with self-saving Personio opt-in"
```

### Task 8: `TokensSection` + slim `SecuritySection`

**Files:**
- Create: `frontend/src/components/settings/TokensSection.tsx`
- Modify: `frontend/src/components/SecuritySection.tsx` (remove the ApiTokenControls import and its render site)

**Interfaces:**
- Consumes: `ApiTokenControls` (existing, unchanged).
- Produces: `export function TokensSection(): JSX.Element`; `SecuritySection` no longer renders tokens.

- [ ] **Step 1: Write TokensSection**

```tsx
import { ApiTokenControls } from '../ApiTokenControls'

/** Personal access tokens (ADR-021) — their own section: an automation
 *  concern with a wide scopes grid, not an account-security control. */
export function TokensSection() {
  return <ApiTokenControls />
}
```

If `ApiTokenControls` renders without its own `fieldset.settings-group`/legend wrapper (check `frontend/src/components/ApiTokenControls.tsx:49` — it was embedded in SecuritySection's card), wrap it here in `<fieldset class="settings-group">` with a `<legend>` reusing the existing token-section heading key from SecuritySection, and remove that heading from SecuritySection so it is not duplicated.

- [ ] **Step 2: Slim SecuritySection**

In `frontend/src/components/SecuritySection.tsx`: delete the `ApiTokenControls` import line and the `<ApiTokenControls />` element (plus its token-related heading/wrapper markup if any). Nothing else changes — `TwoFactorControls`/`PasskeyControls`/`PasswordChange` keep their exports (TwoFactorGate.tsx depends on them).

- [ ] **Step 3: Verify + commit**

Run: `bun run typecheck && bun run lint && bun run test src/components`
Expected: clean; if `SecuritySection` tests asserted the tokens block, move those assertions to a new `TokensSection.test.tsx`.

```bash
git add frontend/src/components/settings/TokensSection.tsx frontend/src/components/SecuritySection.tsx
git commit -S --signoff -m "feat(frontend): move API tokens out of SecuritySection into TokensSection"
```

### Task 9: Settings shell, routing, CSS, i18n nav keys

**Files:**
- Modify: `frontend/src/pages/Settings.tsx` (becomes the shell — everything else was extracted)
- Modify: `frontend/src/App.tsx:57` (MODAL_SEGMENTS), `:219-224` (BG_PAGES), `:233` (route)
- Modify: `frontend/src/styles/app.css` (settings layout block, near the existing settings styles at ~885-920)
- Modify: `frontend/messages/{en,de,es,fr,ru}.json` (5 new nav keys)
- Modify/Split: `frontend/src/pages/Settings.test.tsx`

**Interfaces:**
- Consumes: `AccountSection`, `AppearanceSection`, `SecuritySection`, `TokensSection`, `SyncSection` (Tasks 5-8).
- Produces: full-page settings at `/settings/:section?`; section keys `account | appearance | security | tokens | sync`.

- [ ] **Step 1: Add the nav message keys (all five catalogs)**

```json
"settings_nav_account":    en "Account & tracking"   de "Konto & Erfassung"      es "Cuenta y registro"        fr "Compte et saisie"         ru "Аккаунт и учёт"
"settings_nav_appearance": en "Appearance"           de "Darstellung"            es "Apariencia"               fr "Apparence"                ru "Оформление"
"settings_nav_security":   en "Security"             de "Sicherheit"             es "Seguridad"                fr "Sécurité"                 ru "Безопасность"
"settings_nav_tokens":     en "API tokens"           de "API-Tokens"             es "Tokens de API"            fr "Jetons d'API"             ru "API-токены"
"settings_nav_sync":       en "Synchronization"      de "Synchronisation"        es "Sincronización"           fr "Synchronisation"          ru "Синхронизация"
```

(Write each into its catalog as a normal `"key": "value"` entry; run `bun run i18n:compile` via `bun run typecheck`.)

- [ ] **Step 2: Rewrite pages/Settings.tsx as the shell**

```tsx
import { A, useParams } from '@solidjs/router'
import { createMemo, For } from 'solid-js'
import { Dynamic } from 'solid-js/web'
import type { Component } from 'solid-js'

import { SecuritySection } from '../components/SecuritySection'
import { AccountSection } from '../components/settings/AccountSection'
import { AppearanceSection } from '../components/settings/AppearanceSection'
import { SyncSection } from '../components/settings/SyncSection'
import { TokensSection } from '../components/settings/TokensSection'
import { m } from '../paraglide/messages.js'

interface Section {
  key: string
  label: () => string
  component: Component
}

// Order = display order. Only the active section mounts (lazy per section:
// e.g. the passkey/token lists fetch only when their section is opened).
const SECTIONS: Section[] = [
  { key: 'account', label: () => m.settings_nav_account(), component: AccountSection },
  { key: 'appearance', label: () => m.settings_nav_appearance(), component: AppearanceSection },
  { key: 'security', label: () => m.settings_nav_security(), component: SecuritySection },
  { key: 'tokens', label: () => m.settings_nav_tokens(), component: TokensSection },
  { key: 'sync', label: () => m.settings_nav_sync(), component: SyncSection },
]

export default function Settings() {
  const params = useParams()
  // Unknown/absent :section falls back to the first section (tolerant, like Admin).
  const active = createMemo(() => SECTIONS.find((s) => s.key === params.section) ?? SECTIONS[0])

  return (
    <section class="form-page settings-page settings-layout">
      <nav class="settings-nav" aria-label={m.settings_title()}>
        <For each={SECTIONS}>
          {(s) => (
            <A
              href={`/settings/${s.key}`}
              class="settings-nav-link"
              aria-current={active().key === s.key ? 'page' : undefined}
            >
              {s.label()}
            </A>
          )}
        </For>
      </nav>
      <div class="settings-content">
        <Dynamic component={active().component} />
      </div>
    </section>
  )
}
```

- [ ] **Step 3: Routing changes in App.tsx**

1. Line 57: `const MODAL_SEGMENTS = new Set(['help', 'billing'])` — settings removed; update the comment above it.
2. Line 233: `<Route path="/settings/:section?" component={Settings} />`
3. BG_PAGES (line 219-224): add `settings: Settings,` so opening `/help` FROM settings renders settings behind the modal instead of falling back to Month.

- [ ] **Step 4: CSS**

Append to the settings block in `frontend/src/styles/app.css` (~line 920), following the existing custom-property style:

```css
/* Settings page: section nav (sidebar ≥768px, scrollable strip below). */
.settings-layout { display: flex; gap: 2rem; align-items: flex-start; }
.settings-nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 0 0 200px; position: sticky; top: 1rem; }
.settings-nav-link {
  display: flex; align-items: center; min-height: 44px; padding: 0 0.75rem;
  border-radius: 6px; color: inherit; text-decoration: none;
}
.settings-nav-link:hover { background: color-mix(in srgb, currentColor 8%, transparent); }
.settings-nav-link[aria-current='page'] {
  background: color-mix(in srgb, currentColor 12%, transparent); font-weight: 600;
}
.settings-content { flex: 1; min-width: 0; }
.settings-instant-badge { font-style: italic; }
@media (max-width: 767px) {
  .settings-layout { flex-direction: column; gap: 1rem; }
  .settings-nav { flex-direction: row; position: static; flex-basis: auto; width: 100%; overflow-x: auto; }
  .settings-nav-link { white-space: nowrap; }
}
```

Check the focus-visible treatment other links get in app.css and match it; verify 7:1 contrast of the active state in both schemes (adjust the color-mix percentages if needed).

- [ ] **Step 5: Adapt the unit tests**

Rewrite `frontend/src/pages/Settings.test.tsx` as shell tests. Mount with a router exactly the way `frontend/src/pages/Admin.test.tsx` mounts its `useParams`-dependent page (copy that wrapper). Move any per-section assertions the old file had into the matching `components/settings/*.test.tsx` (Tasks 5/7 created homes for account + personio; appearance/security assertions move likewise). Shell assertions:

```tsx
it('renders the account section by default', ...)        // route /settings → account legend visible
it('renders the requested section', ...)                  // route /settings/security → 2FA heading visible
it('falls back to account for an unknown section', ...)   // route /settings/nope → account legend visible
it('marks the active section with aria-current', ...)
```

- [ ] **Step 6: Verify**

Run: `bun run typecheck && bun run lint && bun run test`
Expected: green. Then verify in the browser (dev stack): gear icon → full page (no dialog), all five nav entries switch content and URL, back button works, `/ui/settings/tokens` deep-links.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/Settings.tsx frontend/src/pages/Settings.test.tsx frontend/src/App.tsx frontend/src/styles/app.css frontend/messages/
git commit -S --signoff -m "feat(frontend): settings as full page with section navigation"
```

### Task 10: `.well-known/change-password` target

**Files:**
- Modify: `src/Controller/WellKnownController.php:61` (the change-password redirect)

- [ ] **Step 1: Update the redirect target** from `/ui/settings` to `/ui/settings/security` (password managers should land directly on the password form). Adapt its test if one asserts the target (`grep -rn "change-password" tests/`).

- [ ] **Step 2: Verify + commit**

Run: `composer test` (the well-known test), `composer analyze && composer cs-check`

```bash
git add src/Controller/WellKnownController.php tests/
git commit -S --signoff -m "fix: point /.well-known/change-password at the security section"
```

### Task 11: Remove legacy `/settings/save`

**Files:**
- Delete: `src/Controller/Settings/SaveSettingsAction.php`
- Delete/Modify: its tests (`grep -rln "settings/save\|SaveSettingsAction" tests/` — delete tests that only cover the endpoint; port any uncovered behavioral assertion, e.g. locale normalization, into `SettingsActionsTest` if missing)

- [ ] **Step 1: Confirm no remaining consumers**

Run: `grep -rn "settings/save" src/ frontend/src/ templates/ e2e/`
Expected: hits only in e2e helpers (rewritten in Task 12) and the files being deleted. If anything else consumes it, STOP and resolve first.

- [ ] **Step 2: Delete controller + obsolete tests, run gates**

Run: `composer analyze && composer cs-check && composer test`
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add -A src/Controller/Settings/ tests/
git commit -S --signoff -m "refactor: remove legacy POST /settings/save (replaced by PATCH /api/v2/settings)"
```

### Task 12: E2E rewrite

**Files:**
- Modify: `e2e/settings.spec.ts` (helpers at lines 28-95 + all modal-era assumptions)
- Check: `grep -rn "settings" e2e/*.spec.ts e2e/helpers/` for other specs asserting the settings dialog (navigation, accessibility) and adapt them.

**Interfaces:**
- Consumes: the deployed Task 1-11 state; test env renders GERMAN (assert German strings or structure).

- [ ] **Step 1: Rewrite the helpers**

```ts
// Navigate to a settings section and wait for the section nav.
async function goToSettingsPage(page: Page, section = 'account') {
  await page.goto(`/ui/settings/${section}`);
  await page.waitForSelector('.settings-nav', { timeout: 15000 });
}

// Submit the account form and return the settings echoed by the PATCH the UI fired.
async function saveSettingsViaForm(page: Page): Promise<Record<string, unknown>> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/v2/settings') && r.request().method() === 'PATCH'),
    page.locator('form.stack-form button.primary-button').click(),
  ]);
  await page.waitForSelector('.form-status.is-ok', { timeout: 10000 });

  return (await response.json()) as Record<string, unknown>;
}

type BoolSettings = { show_empty_line: boolean; suggest_time: boolean; show_future: boolean };

// Persist a known state via the API (PATCH partial semantics — booleans stay booleans).
async function applySettingsApi(page: Page, settings: BoolSettings): Promise<Record<string, unknown>> {
  const response = await page.request.patch('/api/v2/settings', { data: settings });
  expect(response.ok()).toBeTruthy();

  return (await response.json()) as Record<string, unknown>;
}
```

Adjust every existing test in the file: settings values in assertions change from `0/1` numbers to booleans; grid-effect tests now start with `goToSettingsPage(page, 'account')`.

- [ ] **Step 2: Add page-conversion tests**

```ts
test('settings is a full page with a working section nav', async ({ page }) => {
  await loginWithFrozenClock(page, 'i.myself', 'myself123');
  await goToSettingsPage(page);

  // No dialog anymore — the page itself hosts the content.
  await expect(page.getByRole('dialog')).toHaveCount(0);

  // Nav switches section and URL.
  await page.locator('.settings-nav-link', { hasText: /Sicherheit/ }).click();
  await expect(page).toHaveURL(/\/ui\/settings\/security/);
});

test('deep link opens the security section directly', async ({ page }) => {
  await loginWithFrozenClock(page, 'i.myself', 'myself123');
  await goToSettingsPage(page, 'security');
  // Structure assertion (German UI): the 2FA/password card is on screen.
  await expect(page.locator('.security-block').first()).toBeVisible();
});

test('unknown section falls back to account', async ({ page }) => {
  await loginWithFrozenClock(page, 'i.myself', 'myself123');
  await goToSettingsPage(page, 'does-not-exist');
  await expect(page.locator('form.stack-form')).toBeVisible();
});
```

- [ ] **Step 3: Run the suite**

Run: `make e2e-up && npx playwright test e2e/settings.spec.ts` (then any other adapted specs, then `make e2e-down`)
Expected: green.

- [ ] **Step 4: Commit; open the Phase-2 PR with before/after screenshots**

```bash
git add e2e/
git commit -S --signoff -m "test(e2e): adapt settings specs to the full-page layout"
```

UI PRs need before/after screenshots (capture via the browser driver on the dev stack, viewport ≥1440x900).

---

## Phase 3 — HelpPopover + contextual help

### Task 13: `HelpPopover` component

**Files:**
- Create: `frontend/src/components/HelpPopover.tsx`
- Create: `frontend/src/components/HelpPopover.test.tsx`
- Modify: `frontend/src/styles/app.css` (help styles)
- Modify: `frontend/messages/{en,de,es,fr,ru}.json` (1 key: `help_popover_label`)

**Interfaces:**
- Consumes: `@ark-ui/solid/popover` (dependency present; `DatePopover.tsx` shows the import style).
- Produces: `export function HelpPopover(props: { topic: string; children: JSX.Element }): JSX.Element` — `topic` is the already-localized field/section name; `children` the localized explanation.

- [ ] **Step 1: Write the component**

```tsx
import { Popover } from '@ark-ui/solid/popover'
import type { JSX } from 'solid-js'
import { Portal } from 'solid-js/web'

import { m } from '../paraglide/messages.js'

/** An inline "?" trigger opening a short explanation. For one-line facts keep
 *  using `<small class="field-hint">`; this is for the "what IS a passkey?"
 *  class of help that would bloat the form (spec §7). */
export function HelpPopover(props: { topic: string; children: JSX.Element }) {
  return (
    <Popover.Root lazyMount unmountOnExit>
      <Popover.Trigger class="help-trigger" aria-label={m.help_popover_label({ topic: props.topic })}>
        ?
      </Popover.Trigger>
      <Portal>
        <Popover.Positioner>
          <Popover.Content class="help-popover">
            <Popover.Title class="help-popover-title">{props.topic}</Popover.Title>
            <div class="help-popover-body">{props.children}</div>
          </Popover.Content>
        </Popover.Positioner>
      </Portal>
    </Popover.Root>
  )
}
```

Message key (parameterized — check an existing parameterized key in the catalogs for the exact Paraglide syntax, e.g. `{topic}`):

```json
"help_popover_label": en "Help: {topic}"  de "Hilfe: {topic}"  es "Ayuda: {topic}"  fr "Aide : {topic}"  ru "Справка: {topic}"
```

CSS (44px hit area via padding around a compact visual):

```css
.help-trigger {
  inline-size: 24px; block-size: 24px; padding: 0; margin-inline-start: 0.35rem;
  border: 1px solid currentColor; border-radius: 50%; background: none; color: inherit;
  font-size: 0.8rem; line-height: 1; cursor: pointer; position: relative;
}
/* Extend the hit target to 44px without growing the visual (AAA target size). */
.help-trigger::after { content: ''; position: absolute; inset: -10px; }
.help-popover {
  max-inline-size: 32ch; padding: 0.75rem 1rem; border-radius: 8px;
  background: light-dark(#fff, #2a2a2a); border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
  box-shadow: 0 4px 16px rgb(0 0 0 / 0.2);
}
.help-popover-title { font-weight: 600; margin-block-end: 0.35rem; }
```

Match the existing popover/dialog surface tokens in app.css (search for the DatePopover styles) instead of the literal colors above if tokens exist.

- [ ] **Step 2: Write the test**

```tsx
import { render, screen, fireEvent } from '@solidjs/testing-library'
import { axe } from 'vitest-axe'
import { describe, expect, it } from 'vitest'

import { HelpPopover } from './HelpPopover'

describe('HelpPopover', () => {
  it('opens on click and shows the content', async () => {
    render(() => <HelpPopover topic="Passkeys">Explanation text</HelpPopover>)

    fireEvent.click(screen.getByRole('button', { name: /Passkeys/ }))

    expect(await screen.findByText('Explanation text')).toBeInTheDocument()
  })

  it('has no axe violations when open', async () => {
    const { container } = render(() => <HelpPopover topic="Passkeys">Explanation text</HelpPopover>)
    fireEvent.click(screen.getByRole('button', { name: /Passkeys/ }))
    await screen.findByText('Explanation text')

    expect(await axe(container)).toHaveNoViolations()
  })
})
```

- [ ] **Step 3: Run test, gates, commit**

Run: `bun run test src/components/HelpPopover.test.tsx && bun run typecheck && bun run lint`

```bash
git add frontend/src/components/HelpPopover.tsx frontend/src/components/HelpPopover.test.tsx frontend/src/styles/app.css frontend/messages/
git commit -S --signoff -m "feat(frontend): add reusable HelpPopover component"
```

### Task 14: Help content + integration

**Files:**
- Modify: `frontend/messages/{en,de,es,fr,ru}.json` (6 content keys)
- Modify: `frontend/src/components/SecuritySection.tsx`, `frontend/src/components/ApiTokenControls.tsx`, `frontend/src/components/WorklogImportSection.tsx`, `frontend/src/components/WorklogSyncPreferences.tsx`, `frontend/src/components/settings/AccountSection.tsx`

**Interfaces:**
- Consumes: `HelpPopover` (Task 13).

- [ ] **Step 1: Add the six content keys (all five catalogs)**

```
settings_help_passkeys:
  en "A passkey signs you in with your device's screen lock (fingerprint, face, PIN) instead of a password. It only works on this site and cannot be phished."
  de "Ein Passkey meldet dich mit der Bildschirmsperre deines Geräts an (Fingerabdruck, Gesicht, PIN) statt mit einem Passwort. Er funktioniert nur auf dieser Seite und kann nicht gephisht werden."
  es "Una passkey inicia tu sesión con el bloqueo de pantalla de tu dispositivo (huella, cara, PIN) en lugar de una contraseña. Solo funciona en este sitio y no puede ser víctima de phishing."
  fr "Une passkey vous connecte avec le verrouillage d'écran de votre appareil (empreinte, visage, code PIN) au lieu d'un mot de passe. Elle ne fonctionne que sur ce site et ne peut pas être hameçonnée."
  ru "Passkey выполняет вход с помощью блокировки экрана устройства (отпечаток, лицо, PIN) вместо пароля. Он работает только на этом сайте и не подвержен фишингу."

settings_help_totp:
  en "An authenticator app generates a 6-digit code that changes every 30 seconds. Keep the backup codes safe — they are shown only once and let you in if you lose the device."
  de "Eine Authenticator-App erzeugt einen 6-stelligen Code, der sich alle 30 Sekunden ändert. Bewahre die Backup-Codes sicher auf — sie werden nur einmal angezeigt und helfen, wenn das Gerät verloren geht."
  es "Una aplicación de autenticación genera un código de 6 dígitos que cambia cada 30 segundos. Guarda los códigos de respaldo — se muestran solo una vez y te permiten entrar si pierdes el dispositivo."
  fr "Une application d'authentification génère un code à 6 chiffres qui change toutes les 30 secondes. Conservez les codes de secours — ils ne s'affichent qu'une fois et vous dépannent en cas de perte de l'appareil."
  ru "Приложение-аутентификатор генерирует 6-значный код, меняющийся каждые 30 секунд. Сохраните резервные коды — они показываются только один раз и помогут войти при потере устройства."

settings_help_token_scopes:
  en "Scopes limit what a token may do (resource × read/write). A token can never do more than your account; the secret is shown only once at creation."
  de "Scopes begrenzen, was ein Token darf (Ressource × Lesen/Schreiben). Ein Token kann nie mehr als dein Konto; das Secret wird nur einmal beim Erstellen angezeigt."
  es "Los scopes limitan lo que un token puede hacer (recurso × lectura/escritura). Un token nunca puede más que tu cuenta; el secreto se muestra solo una vez al crearlo."
  fr "Les scopes limitent ce qu'un jeton peut faire (ressource × lecture/écriture). Un jeton ne peut jamais faire plus que votre compte ; le secret ne s'affiche qu'une fois à la création."
  ru "Скоупы ограничивают возможности токена (ресурс × чтение/запись). Токен не может больше, чем ваш аккаунт; секрет показывается только один раз при создании."

settings_help_import_dryrun:
  en "Preview runs the import without writing anything and shows what would be created or skipped. Only Execute changes your worklogs."
  de "Die Vorschau führt den Import aus, ohne etwas zu schreiben, und zeigt, was angelegt oder übersprungen würde. Erst Ausführen ändert deine Buchungen."
  es "La vista previa ejecuta la importación sin escribir nada y muestra lo que se crearía u omitiría. Solo Ejecutar cambia tus registros."
  fr "L'aperçu exécute l'import sans rien écrire et montre ce qui serait créé ou ignoré. Seul Exécuter modifie vos saisies."
  ru "Предпросмотр выполняет импорт без записи и показывает, что будет создано или пропущено. Изменения вносит только запуск."

settings_help_sync_optin:
  en "When enabled, the nightly sync writes your worklogs to the connected Jira. It only ever touches entries of users who opted in."
  de "Wenn aktiviert, überträgt der nächtliche Abgleich deine Buchungen ins verbundene Jira. Er verändert ausschließlich Einträge von Nutzern, die zugestimmt haben."
  es "Si está activado, la sincronización nocturna escribe tus registros en el Jira conectado. Solo toca entradas de usuarios que dieron su consentimiento."
  fr "Si activé, la synchronisation nocturne écrit vos saisies dans le Jira connecté. Elle ne touche que les entrées des utilisateurs ayant donné leur accord."
  ru "Если включено, ночная синхронизация записывает ваши ворклоги в подключённую Jira. Она затрагивает только записи согласившихся пользователей."

settings_help_min_duration:
  en "When you add a new entry, its end time is pre-filled this many minutes after the start. 0 disables the pre-fill."
  de "Beim Anlegen eines neuen Eintrags wird das Ende so viele Minuten nach dem Start vorbelegt. 0 schaltet die Vorbelegung ab."
  es "Al añadir una entrada nueva, la hora de fin se rellena tantos minutos después del inicio. 0 desactiva el prellenado."
  fr "À l'ajout d'une nouvelle saisie, l'heure de fin est préremplie de ce nombre de minutes après le début. 0 désactive le préremplissage."
  ru "При добавлении новой записи время окончания заполняется на указанное число минут после начала. 0 отключает предзаполнение."
```

- [ ] **Step 2: Integrate**

Place `<HelpPopover topic={<localized heading>}>{m.settings_help_…()}</HelpPopover>` directly after:
- the passkey block heading in `SecuritySection.tsx` → `settings_help_passkeys`
- the 2FA block heading in `SecuritySection.tsx` → `settings_help_totp`
- the scopes-grid label in `ApiTokenControls.tsx` → `settings_help_token_scopes`
- the import section heading in `WorklogImportSection.tsx` → `settings_help_import_dryrun`
- the sync-enabled toggle label in `WorklogSyncPreferences.tsx` → `settings_help_sync_optin`
- the min-duration label in `AccountSection.tsx` → `settings_help_min_duration` (replace the existing `settings_min_entry_duration_hint` field-hint if the texts now duplicate; otherwise keep both — hint short, popover long)

Watch out inside `<label>` elements: a button inside a label toggles the input on click. Render the trigger OUTSIDE the `<label>` (sibling in the same `.field` wrapper) wherever the help attaches to a labeled control.

- [ ] **Step 3: Verify + commit**

Run: `bun run typecheck && bun run lint && bun run test` — then check each popover in the browser (both color schemes, keyboard: Tab to trigger, Enter opens, Esc closes).

```bash
git add frontend/src/components/ frontend/messages/
git commit -S --signoff -m "feat(frontend): contextual help popovers across the settings sections"
```

---

## Completion checklist (per phase PR)

- [ ] Backend gates green: `composer analyze && composer cs-check && composer test`
- [ ] Frontend gates green (in `frontend/`): `bun run lint && bun run typecheck && bun run test`
- [ ] E2E green: `make e2e`
- [ ] All new strings present in all five catalogs
- [ ] UI PR includes before/after screenshots (≥1440px viewport)
- [ ] CI is authoritative — verify the PR checks AND their annotations before merge
