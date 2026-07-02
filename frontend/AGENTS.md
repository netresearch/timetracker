<!-- Managed by agent: keep sections and order; edit content, not structure. -->

# AGENTS.md — frontend/

## Overview

SolidJS 1.9 single-page UI (TypeScript strict), served by Symfony under `/ui/`
via `src/Controller/Ui/SpaAction.php` and `templates/ui/index.html.twig`.
Built with Vite 8 (`vite-plugin-symfony`) into `public/build-ui`.
See [`README.md`](README.md) for the full stack description.

## Setup & environment

- Package manager: **bun** (not npm/yarn) — `bun install`
- Dev server with HMR: `bun run dev` (run next to the Symfony app)
- Production build: `bun run build` (writes to `../public/build-ui`)

## Build & tests (prefer file-scoped)

- Lint: `bun run lint` (eslint)
- Typecheck: `bun run typecheck` (paraglide compile + `tsc --noEmit`)
- Tests: `bun run test` (Vitest, jsdom) / `bun run test:watch`
- Single test file: `bun run test src/components/LoginForm.test.tsx`

## Code style & conventions

- Solid is pinned to 1.9.x; write 2.0-ready code:
  no `use:` directives, no `<Index>`, no `classList` (use `class`), isolate `onMount`
- Components in `src/components/`, routed pages in `src/pages/`,
  admin grid config in `src/admin/`, API client in `src/api/`
- Tests are colocated: `Component.tsx` + `Component.test.tsx`

### i18n (Paraglide)

- Messages live in `messages/en.json` and `messages/de.json` — every new
  user-facing string needs BOTH
- Compiled to `src/paraglide/` (via `bun run typecheck` or `i18n:compile`);
  never edit `src/paraglide/` by hand
- Use `import { m } from '../paraglide/messages.js'` and `m.key()` in components

## Security & safety

- The SPA gets its config from `window.APP_CONFIG` (rendered by Twig with
  JSON_HEX_* flags) — never inject unescaped values into the shell template
- All server state goes through TanStack Solid Query and `src/api/`

## PR/commit checklist

- [ ] `bun run lint` clean
- [ ] `bun run typecheck` clean
- [ ] `bun run test` green
- [ ] New strings present in both `messages/en.json` and `messages/de.json`
- [ ] Accessibility upheld: WCAG 2.2 AA + documented AAA subset
      (7:1 contrast in BOTH color schemes, 44px targets, keyboard reachable)

## When stuck

- Read [`frontend/README.md`](README.md) and existing components
- Design tokens: `src/styles/app.css` (CSS custom properties, `light-dark()`)
- Root [`AGENTS.md`](../AGENTS.md) for repo-wide rules

## House Rules

- The main navigation is server-rendered (`templates/partials/header.html.twig`);
  the SPA only syncs its active state (`src/nav.ts`) — don't duplicate the nav
- Keyboard shortcuts are defined centrally in `src/lib/shortcuts.ts` and shown
  in the Help page and ShortcutsDialog — register new ones there
