# ADR-016: SolidJS Frontend Rewrite (ExtJS Replacement)

**Status:** Accepted
**Date:** 2026-06-12 (pilot) — 2026-07-02 (ExtJS fully removed)

## Context

The legacy UI was built on ExtJS, an aging toolkit that blocked modernization:
no maintained upgrade path, poor accessibility, and a webpack-encore build chain
kept alive only for it. The backend had already moved to JSON endpoints, so the
UI could be replaced screen by screen.

## Decision

Replace the ExtJS UI with a **SolidJS single-page application** served under
`/ui/` and delivered as a strangler migration:

- Pilot (monthly overview) landed 2026-06-12 (commit `8c1fe6f9`)
- ExtJS removed completely in [#470](https://github.com/netresearch/timetracker/pull/470)
  (commit `e0c714a7`, 2026-06-30); the webpack-encore toolchain was removed the
  same day (commit `24fcf378`)
- Final consistency pass in [#490](https://github.com/netresearch/timetracker/pull/490)
  (commit `671421f6`, 2026-07-02)

### Stack (verified in [frontend/package.json](../../frontend/package.json) and [frontend/README.md](../../frontend/README.md))

- **SolidJS 1.9** (`solid-js ~1.9.14`, pinned; code follows 2.0-ready conventions)
  with `@solidjs/router` 0.x (minor-pinned)
- **Vite 8** with `vite-plugin-symfony`; the Symfony side integrates via
  `pentatrion/vite-bundle ^8.2` ([composer.json](../../composer.json)) — the SPA is
  rendered by [`SpaAction`](../../src/Controller/Ui/SpaAction.php)
  (`GET /ui/{path}` catch-all) through
  [templates/ui/index.html.twig](../../templates/ui/index.html.twig)
- **Tailwind CSS 4** plus own design tokens (CSS custom properties with
  `light-dark()`)
- **Ark UI** headless components (`@ark-ui/solid`)
- **TanStack Solid Query** for server state (`@tanstack/solid-query`)
- **Paraglide JS** for i18n (English/German message catalogs in `frontend/messages/`)
- **bun** as package manager and build runtime (`frontend/bun.lock`)
- Tests: **Vitest** (jsdom, `@solidjs/testing-library`, `vitest-axe`) plus
  **Playwright** end-to-end tests at the repo root
  ([playwright.config.ts](../../playwright.config.ts), Node >= 26)

### Alternatives considered

- **React**: largest ecosystem, but heavier runtime (VDOM) for a form/grid-heavy app
- **Vue**: solid option, no strong advantage over Solid's fine-grained reactivity
- **Keep ExtJS**: rejected — unmaintained, inaccessible, blocked every other
  frontend improvement

## Consequences

### Positive

- **Accessibility as a target**: WCAG 2.2 AA plus a documented AAA subset
  (7:1 contrast in both color schemes, 44px targets, focus appearance, keyboard-no-exception,
  no timeouts, reduced motion) — see [frontend/README.md](../../frontend/README.md);
  enforced in unit tests via `vitest-axe` and in E2E via `@axe-core/playwright`
- Modern, typed (strict TypeScript) codebase with HMR dev workflow (`bun run dev`)
- Backend config reaches the SPA via a Twig-injected `window.APP_CONFIG` — no
  runtime config fetch

### Negative / accepted trade-offs

- **Hybrid shell**: the header is server-rendered
  ([templates/partials/header.html.twig](../../templates/partials/header.html.twig),
  included by the SPA shell), and the login page remains a server-rendered Twig
  page ([templates/login.html.twig](../../templates/login.html.twig)) — two rendering
  worlds must stay visually consistent
- Solid is pinned to 1.9.x until 2.0 stabilizes; conventions (no `use:` directives,
  no `<Index>`, no `classList`) are documented in
  [frontend/README.md](../../frontend/README.md)
- A second toolchain (bun/Vite/TypeScript) alongside the PHP stack

## Related ADRs

- [ADR-014](ADR-014-typography-and-font-preferences.md): Typography and Font Preferences
  (builds on the SolidJS `/ui` shell)
- [ADR-007](ADR-007-api-design-patterns.md): API Design Patterns (the SPA consumes the
  existing session-authenticated JSON endpoints)
