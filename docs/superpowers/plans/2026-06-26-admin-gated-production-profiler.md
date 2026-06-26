# Admin-gated Production Profiler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a second, prod-like `:profiling` container image (alongside the unchanged `:production` image) in which the full Symfony web profiler is available but exposed only to admins, so real production profiling data can be captured for issue reports.

**Architecture:** The default production image stays profiler-free. A new `APP_ENV=profiling` image (debug off, optimized, but with the dev bundles installed) enables the profiler with `collect: false`; a `kernel.request` subscriber turns collection on only for `ROLE_ADMIN` requests, the `/_profiler` + `/_wdt` routes are `ROLE_ADMIN`-locked, the sensitive data collectors are stripped, and Doctrine query profiling is on so the DB panel works. CI builds and pushes `:profiling`; an operator switches the server to it on demand, then back.

**Tech Stack:** PHP 8.5, Symfony 7.3 (MicroKernelTrait), Doctrine, web-profiler-bundle/debug-bundle (already `require-dev`), Docker buildx Bake, GitHub Actions.

## Global Constraints

- Every PHP file starts with the license header block (`Copyright (c) 2025-2026 Netresearch DTT GmbH` / `SPDX-License-Identifier: AGPL-3.0-only`) then `declare(strict_types=1);` — copy verbatim from any file in `src/`.
- New classes are `final readonly` where they hold only injected dependencies (matches `src/EventSubscriber/AccessDeniedSubscriber.php`).
- Unit tests live under `tests/EventSubscriber/` (or matching `tests/` subdir) so they belong to the `unit` PHPUnit testsuite; namespace `Tests\…`; `final class`, extends `PHPUnit\Framework\TestCase`, annotate with `#[CoversClass(...)]`.
- Run unit tests: `composer test:unit`. Static analysis: `composer analyze` (PHPStan, runs at `-d memory_limit=1G`). Style: `composer cs-fix` before every commit, then `composer cs-check` must pass.
- Commits: Conventional Commit subject, **signed off** (`git commit -s`), **no** AI/bot attribution or Co-Authored-By lines.
- The default `production` image and `prod` env must remain functionally unchanged — every new bundle/config/service is scoped to the `profiling` env only.

---

### Task 1: Admin-gate subscriber (enable the profiler only for admins)

A `kernel.request` subscriber that calls `Profiler::enable()` only when the authenticated user has `ROLE_ADMIN`. With the profiling env's `collect: false` default, this is the sole switch that turns collection (and therefore the toolbar) on — and only for admins. Pure logic, unit-tested with no kernel/DB.

**Files:**
- Create: `src/EventSubscriber/ProfilerAdminGateSubscriber.php`
- Test: `tests/EventSubscriber/ProfilerAdminGateSubscriberTest.php`

**Interfaces:**
- Produces: `App\EventSubscriber\ProfilerAdminGateSubscriber` with constructor `__construct(Security $security, #[Autowire(service: 'profiler')] Profiler $profiler)` and method `onKernelRequest(RequestEvent $event): void`. Subscribes to `KernelEvents::REQUEST` at priority `4` (after the firewall at 8, before the controller). It is registered as a service **only** in the `profiling` env (Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\EventSubscriber\ProfilerAdminGateSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\ProfilerStorageInterface;

/**
 * @internal
 */
#[CoversClass(ProfilerAdminGateSubscriber::class)]
final class ProfilerAdminGateSubscriberTest extends TestCase
{
    private function event(): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST);
    }

    private function profiler(): Profiler
    {
        // Start disabled, mirroring the profiling env's `collect: false` default.
        return new Profiler($this->createMock(ProfilerStorageInterface::class), null, false);
    }

    public function testEnablesProfilerForAdmin(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        $profiler = $this->profiler();

        (new ProfilerAdminGateSubscriber($security, $profiler))->onKernelRequest($this->event());

        self::assertTrue($profiler->isEnabled());
    }

    public function testLeavesProfilerDisabledForNonAdmin(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);
        $profiler = $this->profiler();

        (new ProfilerAdminGateSubscriber($security, $profiler))->onKernelRequest($this->event());

        self::assertFalse($profiler->isEnabled());
    }

    public function testSubscribesToKernelRequest(): void
    {
        self::assertArrayHasKey('kernel.request', ProfilerAdminGateSubscriber::getSubscribedEvents());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:unit -- --filter ProfilerAdminGateSubscriberTest`
Expected: FAIL — `Class "App\EventSubscriber\ProfilerAdminGateSubscriber" not found`.

- [ ] **Step 3: Write the minimal implementation**

```php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Turns the profiler on only for admin requests. Registered solely in the
 * `profiling` env (config/services_profiling.yaml), where the profiler is
 * configured with `collect: false`; this is the only switch that enables
 * collection — and therefore the web-debug-toolbar — and only for ROLE_ADMIN.
 * Runs after the firewall (priority 8) so the security token is resolved.
 */
final readonly class ProfilerAdminGateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        #[Autowire(service: 'profiler')]
        private Profiler $profiler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // isGranted() resolves the lazy firewall token; false for anonymous
        // requests (e.g. the login page), so credentials are never profiled.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $this->profiler->enable();
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:unit -- --filter ProfilerAdminGateSubscriberTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Static analysis + style**

Run: `composer cs-fix && composer analyze`
Expected: cs-fix reports the file formatted; PHPStan reports no errors.

- [ ] **Step 6: Commit**

```bash
git add src/EventSubscriber/ProfilerAdminGateSubscriber.php tests/EventSubscriber/ProfilerAdminGateSubscriberTest.php
git commit -s -m "feat(profiler): gate profiler collection to admin requests"
```

---

### Task 2: Strip sensitive data collectors in the profiling env

A compiler pass that removes the `dump` and `config` data collectors so those panels (which can surface env/secret data) never exist in the profiling image. Registered from `Kernel::build()` only when the env is `profiling`.

**Files:**
- Create: `src/DependencyInjection/Compiler/RemoveSensitiveCollectorsPass.php`
- Create: `tests/DependencyInjection/Compiler/RemoveSensitiveCollectorsPassTest.php`
- Modify: `src/Kernel.php`
- Modify: `phpunit.xml.dist` (add `tests/DependencyInjection` to the `unit` testsuite)

**Interfaces:**
- Produces: `App\DependencyInjection\Compiler\RemoveSensitiveCollectorsPass implements CompilerPassInterface` with `process(ContainerBuilder $container): void`, removing service ids `data_collector.dump` and `data_collector.config` when present.

- [ ] **Step 1: Add the test directory to the unit suite**

In `phpunit.xml.dist`, inside `<testsuite name="unit">`, add a line after `<directory>tests/DTO/Jira</directory>`:

```xml
            <directory>tests/DependencyInjection</directory>
```

- [ ] **Step 2: Write the failing test**

```php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DependencyInjection\Compiler;

use App\DependencyInjection\Compiler\RemoveSensitiveCollectorsPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
#[CoversClass(RemoveSensitiveCollectorsPass::class)]
final class RemoveSensitiveCollectorsPassTest extends TestCase
{
    public function testRemovesDumpAndConfigCollectorsButKeepsOthers(): void
    {
        $container = new ContainerBuilder();
        $container->register('data_collector.dump', stdClass::class);
        $container->register('data_collector.config', stdClass::class);
        $container->register('data_collector.request', stdClass::class);

        (new RemoveSensitiveCollectorsPass())->process($container);

        self::assertFalse($container->hasDefinition('data_collector.dump'));
        self::assertFalse($container->hasDefinition('data_collector.config'));
        self::assertTrue($container->hasDefinition('data_collector.request'));
    }

    public function testIsANoOpWhenCollectorsAbsent(): void
    {
        $container = new ContainerBuilder();

        (new RemoveSensitiveCollectorsPass())->process($container);

        self::assertFalse($container->hasDefinition('data_collector.dump'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `composer test:unit -- --filter RemoveSensitiveCollectorsPassTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the compiler pass**

```php
<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the profiler data collectors that can surface env/secret data
 * (dump, config). Registered from Kernel::build() in the `profiling` env only,
 * so the kept panels are DB/Time/Memory/Request/Routing/Events/Logs/Cache.
 */
final class RemoveSensitiveCollectorsPass implements CompilerPassInterface
{
    private const array SENSITIVE_COLLECTORS = [
        'data_collector.dump',
        'data_collector.config',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::SENSITIVE_COLLECTORS as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer test:unit -- --filter RemoveSensitiveCollectorsPassTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Register the pass in the kernel (profiling env only)**

In `src/Kernel.php`, add the imports near the existing `use` block:

```php
use App\DependencyInjection\Compiler\RemoveSensitiveCollectorsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
```

Add this method to the `Kernel` class (after `getProjectDir()`):

```php
    #[Override]
    protected function build(ContainerBuilder $container): void
    {
        if ($this->environment === 'profiling') {
            $container->addCompilerPass(new RemoveSensitiveCollectorsPass());
        }
    }
```

- [ ] **Step 7: Static analysis + style**

Run: `composer cs-fix && composer analyze`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/DependencyInjection/Compiler/RemoveSensitiveCollectorsPass.php tests/DependencyInjection/Compiler/RemoveSensitiveCollectorsPassTest.php src/Kernel.php phpunit.xml.dist
git commit -s -m "feat(profiler): strip dump/config collectors in the profiling env"
```

---

### Task 3: Wire the `profiling` Symfony environment

Enable the bundles, profiler, routes, Doctrine query profiling, the route lock, and the admin-gate service — all scoped to `profiling`. Verified by compiling the container and inspecting routes/config in that env. No production/prod-env file changes except one always-inert `access_control` line.

**Files:**
- Modify: `config/bundles.php`
- Modify: `config/services.yaml`
- Create: `config/services_profiling.yaml`
- Create: `config/packages/profiling/web_profiler.yaml`
- Create: `config/packages/profiling/doctrine.yaml`
- Create: `config/packages/profiling/security.yaml`
- Create: `config/routes/profiling/web_profiler.yaml`
- Modify: `config/packages/security.yaml`

**Interfaces:**
- Consumes: `App\EventSubscriber\ProfilerAdminGateSubscriber` (Task 1), the `profiler` service (from WebProfilerBundle).

- [ ] **Step 1: Enable the bundles for `profiling`**

In `config/bundles.php`, change the last two entries to:

```php
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true, 'profiling' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true, 'test' => true, 'profiling' => true],
```

- [ ] **Step 2: Exclude the subscriber from the global autoload**

In `config/services.yaml`, inside the `App\` resource `exclude:` list, add:

```yaml
            - '../src/EventSubscriber/ProfilerAdminGateSubscriber.php'
```

- [ ] **Step 3: Register the subscriber only in the profiling env**

Create `config/services_profiling.yaml`:

```yaml
# Loaded only when APP_ENV=profiling (Symfony imports config/services_<env>.yaml).
# The profiler service exists here (WebProfilerBundle is enabled for this env),
# so the gate subscriber can autowire it.
services:
    App\EventSubscriber\ProfilerAdminGateSubscriber:
        autowire: true
        autoconfigure: true
```

- [ ] **Step 4: Enable the profiler, dormant by default**

Create `config/packages/profiling/web_profiler.yaml`:

```yaml
framework:
    profiler:
        enabled: true
        # Dormant: nothing is collected unless ProfilerAdminGateSubscriber
        # calls Profiler::enable() for an admin request.
        collect: false

web_profiler:
    toolbar: true
    intercept_redirects: false
```

- [ ] **Step 5: Turn on Doctrine query profiling (prod-like cache otherwise)**

Create `config/packages/profiling/doctrine.yaml`:

```yaml
# Reuse the production ORM/cache setup so timings stay representative, then
# turn on DBAL query profiling so the profiler's Database panel (queries,
# timings, EXPLAIN) populates — kernel.debug is false in this env, so the base
# `profiling: '%kernel.debug%'` would otherwise leave it empty.
imports:
    - { resource: ../prod/doctrine.yaml }

doctrine:
    dbal:
        profiling: true
        profiling_collect_backtrace: false
```

- [ ] **Step 6: Send `/_profiler` through the authenticated firewall**

Create `config/packages/profiling/security.yaml`:

```yaml
# The shared `dev` firewall is `security: false` and matches ^/_(profiler|wdt).
# Narrow it to assets in this env so the profiler paths fall through to the
# `main` (authenticated) firewall, where the access_control rule below applies.
security:
    firewalls:
        dev:
            pattern: ^/(css|images|js)/
```

- [ ] **Step 7: Load the profiler routes in this env**

Create `config/routes/profiling/web_profiler.yaml` with the same content as `config/routes/dev/web_profiler.yaml` (copy it verbatim — it defines `web_profiler_wdt` at prefix `/_wdt` and `web_profiler_profiler` at prefix `/_profiler`).

```bash
cp config/routes/dev/web_profiler.yaml config/routes/profiling/web_profiler.yaml
```

- [ ] **Step 8: Lock the profiler routes to admins**

In `config/packages/security.yaml`, add this line to `access_control` immediately **above** the final `- { path: ^/, roles: IS_AUTHENTICATED_FULLY }`:

```yaml
        - { path: ^/_(profiler|wdt), roles: ROLE_ADMIN }
```

(Inert in `dev`/`test` — the `dev` firewall short-circuits those paths there — and in `prod` the routes do not exist.)

- [ ] **Step 9: Verify the profiling container compiles and is wired correctly**

```bash
APP_ENV=profiling APP_DEBUG=0 php bin/console cache:clear --no-debug
APP_ENV=profiling APP_DEBUG=0 php bin/console debug:container ProfilerAdminGateSubscriber
APP_ENV=profiling APP_DEBUG=0 php bin/console debug:router | grep _profiler
APP_ENV=profiling APP_DEBUG=0 php bin/console debug:config security | grep -A2 'dev'
```
Expected: cache clears with no error; the subscriber service is listed; `/_profiler` + `/_wdt` routes are present; the `dev` firewall pattern is `^/(css|images|js)/`.

- [ ] **Step 10: Verify prod is untouched**

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --no-debug
APP_ENV=prod APP_DEBUG=0 php bin/console debug:router | grep _profiler || echo "no profiler routes in prod (correct)"
```
Expected: prod cache clears cleanly; no `_profiler` routes in prod.

- [ ] **Step 11: Run the unit suite + style/analysis**

Run: `composer test:unit && composer cs-check && composer analyze`
Expected: all green.

- [ ] **Step 12: Commit**

```bash
git add config/bundles.php config/services.yaml config/services_profiling.yaml config/packages/profiling/ config/routes/profiling/ config/packages/security.yaml
git commit -s -m "feat(profiler): wire the profiling Symfony environment"
```

---

### Task 4: Build the prod-like `profiling` Docker stage

A new Dockerfile stage that takes the built `deps` stage (full source + frontend, no Xdebug), installs the dev dependencies (which include `web-profiler-bundle`/`debug-bundle`), sets `APP_ENV=profiling` with debug off, warms the profiling cache with an optimized autoloader, and runs as the `app` user with the production entrypoint.

**Files:**
- Modify: `Dockerfile` (append a `profiling` stage)

**Interfaces:**
- Consumes: the `deps` and `base`/`production` stages and `docker/php/docker-entrypoint.sh`, `docker/php/healthcheck.sh`.

- [ ] **Step 1: Add the `profiling` stage**

Append to `Dockerfile` (after the `production` stage):

```dockerfile
# =============================================================================
# PROFILING - Prod-like image WITH the Symfony profiler (admin-gated).
# Never the default deployment: an operator switches the server to :profiling
# on demand to capture production profiling data, then switches back.
# =============================================================================
FROM deps AS profiling

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Add dev dependencies (web-profiler-bundle, debug-bundle, stopwatch) on top of
# the prod vendor tree, keeping an optimized, authoritative autoloader.
ENV CAPTAINHOOK_DISABLE=true
ENV APP_ENV=profiling
ENV APP_DEBUG=0
RUN composer install --ignore-platform-req=php \
    && composer dump-autoload --optimize --classmap-authoritative \
    && APP_ENV=profiling APP_DEBUG=0 php bin/console cache:clear --no-debug \
    && APP_ENV=profiling APP_DEBUG=0 php bin/console cache:warmup --no-debug \
    && chown -R app:app var/

COPY --chmod=755 docker/php/healthcheck.sh /usr/local/bin/healthcheck
COPY --chmod=755 docker/php/docker-entrypoint.sh /usr/local/bin/app-entrypoint

ARG APP_BUILD_REVISION=""
ARG APP_BUILD_REF=""
ARG APP_BUILD_DATE=""
ENV APP_BUILD_REVISION=${APP_BUILD_REVISION}
ENV APP_BUILD_REF=${APP_BUILD_REF}
ENV APP_BUILD_DATE=${APP_BUILD_DATE}

USER app

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD /usr/local/bin/healthcheck
```

- [ ] **Step 2: Build the stage**

Run: `docker build --target profiling -t timetracker:profiling-local .`
Expected: the build completes. (If Docker is not running in this environment, start it first — `sudo service docker start` — Docker does not auto-start here.)

- [ ] **Step 3: Verify the image runs prod-like with the profiler present**

```bash
docker run --rm --entrypoint php timetracker:profiling-local bin/console about | grep -E "Environment|Debug"
docker run --rm --entrypoint php timetracker:profiling-local bin/console debug:router | grep _profiler
```
Expected: `Environment  profiling`, `Debug  false`; `/_profiler` + `/_wdt` routes present.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile
git commit -s -m "build(profiler): add prod-like APP_ENV=profiling image stage"
```

---

### Task 5: Publish the `:profiling` image from the pipeline

Add a Bake target for the new stage and a CI step that builds + pushes `:profiling` on the default branch (never auto-deployed).

**Files:**
- Modify: `docker-bake.hcl`
- Modify: `.github/workflows/docker-publish.yml`

**Interfaces:**
- Consumes: the `profiling` Dockerfile stage (Task 4).

- [ ] **Step 1: Add the bake target**

In `docker-bake.hcl`, after the `app-e2e` target, add:

```hcl
# Profiling image (prod-like + Symfony profiler, admin-gated). Built by CI,
# never the default deployment — operators switch to :profiling on demand.
target "app-profiling" {
  inherits = ["_common"]
  target   = "profiling"
  tags = compact([
    "${REGISTRY}/${IMAGE_NAME}:profiling",
    GIT_SHA != "" ? "${REGISTRY}/${IMAGE_NAME}:profiling-${GIT_SHA}" : "",
  ])
}
```

In the same file, add `app-profiling` to the `all` group:

```hcl
group "all" {
  targets = ["app", "app-dev", "app-tools", "app-e2e", "app-profiling"]
}
```

- [ ] **Step 2: Verify the bake config resolves**

Run: `docker buildx bake --print app-profiling`
Expected: JSON showing `target = "profiling"` and the `:profiling` tag(s); no error.

- [ ] **Step 3: Add the CI publish step**

In `.github/workflows/docker-publish.yml`, after the "Build and push E2E image" step, add:

```yaml
      - name: Build and push profiling image
        uses: docker/bake-action@6614cfa25eff9a0b2b2697efb0b6159e7680d584 # v7.2.0
        env:
          GIT_SHA: ${{ github.sha }}
        with:
          source: .
          targets: app-profiling
          files: docker-bake.hcl
          push: ${{ github.event_name != 'pull_request' }}
          set: |
            *.cache-from=type=gha
            *.cache-to=type=gha,mode=max,ignore-error=true
```

- [ ] **Step 4: Verify the workflow is valid YAML**

Run: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/docker-publish.yml')); print('ok')"`
Expected: `ok`.

- [ ] **Step 5: Commit**

```bash
git add docker-bake.hcl .github/workflows/docker-publish.yml
git commit -s -m "ci(profiler): build and push the :profiling image"
```

---

### Task 6: Document the switch in the deploy runbook

Record how to switch the server to `:profiling` and back, and that it is never the default.

**Files:**
- Modify: the deploy/ops doc (find it: `ls docs/ AGENTS.md README.md docker/README.md 2>/dev/null` and pick the existing deployment/runbook doc; if none exists, add a short `docs/profiling.md`).

- [ ] **Step 1: Add the runbook section**

Document, in prose: pull `:profiling`, switch the running container's image tag to it (compose image override / hot-deploy), reproduce the issue **as an admin**, open the web-debug-toolbar / `/_profiler/{token}?panel=db` for the slow request (e.g. `/getAllCustomers`), then **switch the tag back to `:production`**. Note: non-admins see nothing on this image; `:profiling` is never the default deployment; collected profiles vanish when the container is replaced.

- [ ] **Step 2: Commit**

```bash
git add -A
git commit -s -m "docs(profiler): document switching to the profiling image"
```

---

## Self-Review

**Spec coverage:**
- Two images / pipeline → Tasks 4, 5. ✓
- Prod-like profiling env → Task 3 (config) + Task 4 (`APP_DEBUG=0`, optimized autoloader, prod doctrine cache import). ✓
- Profiler dormant, admin-only activation → Task 3 (`collect: false`) + Task 1 (gate subscriber). ✓
- `/_profiler` ROLE_ADMIN-locked, incl. the `dev`-firewall pitfall → Task 3 Steps 6 & 8. ✓
- DB panel works (Doctrine profiling) → Task 3 Step 5. ✓
- Sensitive collectors removed → Task 2. ✓
- Production/prod env untouched → Task 3 Step 10 verifies; bundles stay `require-dev` (only the profiling image installs them). ✓
- Ops/runbook → Task 6. ✓
- Time-panel/`stopwatch` caveat (spec §7): `composer install` (dev) in Task 4 pulls `symfony/stopwatch` transitively via web-profiler-bundle; if the Time panel is empty in Task 4 Step 3, add `symfony/stopwatch` explicitly to `require-dev` — noted here rather than as a separate task since it is a verify-and-maybe-add.

**Placeholder scan:** none — every code/config step shows full content; verification steps give exact commands + expected output.

**Type consistency:** `ProfilerAdminGateSubscriber(Security, Profiler)` and `RemoveSensitiveCollectorsPass::process(ContainerBuilder)` are used identically in their tests, the kernel wiring, and `config/services_profiling.yaml`. Service id `profiler` and route prefixes `/_profiler` `/_wdt` are consistent across Tasks 1, 3.
