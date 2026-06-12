# timetracker frontend (SolidJS)

The new UI, replacing the legacy ExtJS app one view at a time
(strangler-fig migration). Served by Symfony under `/ui/` via
[pentatrion/vite-bundle](https://symfony-vite.pentatrion.com/)
(`config/packages/pentatrion_vite.yaml`, `src/Controller/Ui/SpaAction.php`,
`templates/ui/index.html.twig`).

## Stack

- [SolidJS](https://www.solidjs.com/) 1.9 + TypeScript (strict)
- Vite 8 (`vite-plugin-symfony` writes `public/build/ui/.vite/entrypoints.json`)
- [Ark UI](https://ark-ui.com/) headless components + own design tokens
  (CSS custom properties, `light-dark()`, see `src/styles/app.css`)
- [TanStack Solid Query](https://tanstack.com/query) for server state
- [Paraglide JS](https://inlang.com/m/gerre34r/library-inlang-paraglideJs)
  for i18n (DE/EN, messages in `messages/`, compiled to `src/paraglide/`)
- Vitest (jsdom) + @solidjs/testing-library + vitest-axe

## Commands (bun is the package manager)

```bash
bun install            # install dependencies
bun run dev            # vite dev server with HMR (run next to the Symfony app)
bun run build          # production build into ../public/build/ui
bun run lint           # eslint
bun run typecheck      # paraglide compile + tsc --noEmit
bun run test           # vitest
```

## Accessibility

Target: WCAG 2.2 AA plus a documented AAA subset (7:1 contrast, 44px
targets, focus appearance, keyboard-no-exception, no timeouts, reduced
motion). Every design-token pair must keep >= 7:1 contrast in BOTH color
schemes. vitest-axe needs jsdom (incompatible with happy-dom).

## Conventions

- Solid is pinned to 1.9.x; write 2.0-ready code: no `use:` directives,
  no `<Index>`, no `classList` (use `class`), isolate `onMount`.
- `@solidjs/router` is 0.x — pin the minor version.
- Use `window.localStorage` (never the bare global — Node's experimental
  global shadows it under test runners).
- Backend config reaches the app via `window.APP_CONFIG`
  (Twig-injected, see `src/config.ts`) — no runtime config fetch.
