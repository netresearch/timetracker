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
- a11y assertions use `vitest-axe` — it requires jsdom (incompatible with happy-dom)

## Code style & conventions

- Solid is pinned to 1.9.x; write 2.0-ready code:
  no `use:` directives, no `<Index>`, no `classList` (use `class`), isolate `onMount`
- Components in `src/components/`, routed pages in `src/pages/`,
  admin grid config in `src/admin/`, API client in `src/api/`
- Tests are colocated: `Component.tsx` + `Component.test.tsx`

### i18n (Paraglide)

- Messages live in `messages/{en,de,es,fr,ru}.json` — every new user-facing
  string needs ALL five catalogs (identical key sets)
- Compiled to `src/paraglide/` (via `bun run typecheck` or `i18n:compile`);
  never edit `src/paraglide/` by hand
- Use `import { m } from '../paraglide/messages.js'` and `m.key()` in components

### Stack decisions

- Ark UI (`@ark-ui/solid`), not Kobalte, for headless components; Tailwind 4
  (`tailwindcss` + `@tailwindcss/vite`) plus the app's own semantic design tokens
- Don't re-propose Vue or Svelte — an earlier Vue-3 `timetracker-ui` plan is
  superseded by the SolidJS decision

## Security & safety

- The SPA gets its config from `window.APP_CONFIG` (rendered by Twig with
  JSON_HEX_* flags) — never inject unescaped values into the shell template
- All server state goes through TanStack Solid Query and `src/api/`

## PR/commit checklist

- [ ] `bun run lint` clean
- [ ] `bun run typecheck` clean
- [ ] `bun run test` green
- [ ] New strings present in all `messages/*.json` catalogs (en/de/es/fr/ru)
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
- `@ark-ui/solid` ships no Calendar/DatePicker (only Dialog/Combobox/Popover
  shells) — hand-roll date UI with `<For>` (role=grid, roving tabindex) and
  reuse `parseUserDate`/`formatWith` (`src/lib/dateFormat.ts`) so the app stays
  authoritative over the user's date-format setting. Wiring an Ark Popover into
  an on-blur-commit inline editor needs cooperating focus guards:
  `autoFocus={false}`, mousedown-preventDefault on trigger+content,
  `closeOnEscape={false}` + `restoreFocus={false}` (let the input own Escape,
  not zag), an `onBlur` guard that ignores focus into `[data-date-popup]`, and
  extending the row-leave save-whitelist. Portal to `<body>` to escape the
  table's overflow scroll container — verify in a real browser (invisible to
  jsdom)
- Admin + Worklog inline grid editing follows "Philosophy A": `gridNavigation.ts`
  (gridNav) stays a pure navigation/ARIA controller and the SINGLE owner of the
  roving-tabindex invariant; edit state lives in the consumer (AdminCrudShell /
  Tracking) via the shared `createInlineGridEdit` controller
  (`src/lib/inlineGridEdit.tsx`), cooperating through thin gridNav hooks
  (`onActivate`, `moveRef`, `data-inline-editing`, `onRowSelectToggle`,
  `onPageEdge`) — never make the consumer a second writer of the roving
  invariant. Save is auto-save-on-completeness (`config.invalidFields`
  auto-flushes a valid draft; a quiet auto-fail sets hints not a rowError; the
  disk icon forces a save and row-leave shows the full error). Relation cells
  use `ChipSelect` (`src/lib/chipSelect.tsx`, an Ark Combobox) body-portalled
  (whitelist `data-chipselect-popup`) to escape the table scroll container
