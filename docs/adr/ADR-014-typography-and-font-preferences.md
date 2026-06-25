# ADR-014: Typography and Font Preferences

## Status

Accepted

## Date

2026-06-25

## Context and Problem Statement

The SolidJS `/ui` shell needs a deliberate typographic system, and we are
repeatedly asked _why_ we ship the fonts we do and _whether_ font usage can
become a user choice. This ADR is the single reference for both questions.

Requirements:

- **Legibility first.** The app is a data-dense tool (grids of times, tickets,
  numbers) used daily; the body face must disambiguate similar glyphs
  (`I`/`l`/`1`, `O`/`0`, `rn`/`m`) and read well at small sizes. This continues
  the codebase's WCAG-AAA posture (see the contrast tokens in `app.css`).
- **No third-party requests.** Loading fonts from a CDN (Google Fonts et al.)
  leaks client IPs to a third party (a GDPR concern for a staff tool) and adds a
  runtime dependency that fails in restricted/offline networks.
- **A clear brand voice** for headings without compromising body readability.
- **User control.** Some users read better with a different family
  (e.g. dyslexia-friendly faces) or a larger size. The mechanism must not
  regress the no-FOUC, no-third-party guarantees above.

## Decision Drivers

- **Accessibility / legibility** — the dominant driver for the body face.
- **Privacy & reliability** — self-hosting over any CDN.
- **Performance** — small Latin subsets; pay for optional faces only on demand.
- **Consistency** — reuse the existing "global visual preference" mechanism
  (theme, density) rather than inventing a new one.
- **Maintainability** — one source of truth for the typographic system.

## Decision

### 1. Two design tokens are the single source of truth

All typography flows through two CSS custom properties in `app.css`:

```css
--font-display: "Bricolage Grotesque Variable", system-ui, sans-serif; /* headings, chrome */
--font-body:    "Atkinson Hyperlegible",        system-ui, sans-serif; /* body, data */
```

Nothing references a font family directly; components only use the tokens. This
is what makes a user preference cheap (see §4).

### 2. The faces, and why

| Role | Face | Rationale |
|------|------|-----------|
| Body / data | **Atkinson Hyperlegible** (400, 700) | Designed by the Braille Institute specifically for low-vision legibility; disambiguates `I`/`l`/`1`, `O`/`0`, `rn`/`m`. The right default for a number-dense daily tool. |
| Headings / chrome | **Bricolage Grotesque** (variable, 200–800) | A distinctive modern grotesque that gives the UI a brand voice in headings; one variable file covers every weight. |
| Fallback | `system-ui, sans-serif` | Renders instantly and degrades gracefully if a `woff2` ever fails to load. |

### 3. Self-hosted, subset, `font-display: swap`

The `woff2` files are vendored under `frontend/src/assets/fonts/` (Latin subset,
~17–41 KB each) and declared with `@font-face` — **no CDN, no third-party
request**. `font-display: swap` paints text immediately in the fallback and
swaps when the web font arrives (no invisible text).

### 4. Font usage is a user preference (client-side)

Two opt-in preferences, both layered on top of §1 without touching component code:

- **Body font** — `:root[data-font="…"]` overrides `--font-body` only (headings
  keep the brand face):
  - _(default, no attribute)_ → Atkinson Hyperlegible
  - `system` → the OS UI font (zero download, native feel)
  - `dyslexic` → **OpenDyslexic** (weighted-bottom letterforms some readers with
    dyslexia prefer), vendored like the others but **only downloaded when
    selected** (the `@font-face` is lazy).
- **Text size** — a `--font-scale` multiplier (1 / 1.15 / 1.3) applied to the
  body font-size, so the reading text enlarges without a separate layout.

Both are stored in `localStorage` (keys `timetracker-font`,
`timetracker-font-scale`) and **applied before first paint** by an inline
`templates/partials/font-init.html.twig`, mirroring the existing
`theme-init` / `density-init` partials (no flash, framework-neutral). The
SolidJS Settings page edits them live via `frontend/src/lib/fontPref.ts`, which
shares the same keys and apply logic.

**Why client-side, not a server setting:** it matches the existing
client-only UI preferences (date format, grid-enter behaviour), needs no schema
migration, and applies instantly with no round-trip. The trade-off is that the
choice is per-device, not synced across devices — acceptable for a rendering
preference (the same reasoning the theme and density toggles already use).

## Considered Options

### Body font as a server-persisted user column (Rejected for now)

**Pros:** syncs across devices like `locale`.
**Cons:** schema migration + DTO + save round-trip for a pure rendering
preference; inconsistent with the theme/density/date-format precedents, which
are all client-side. Can be revisited if users ask for cross-device sync.

### A header toggle button like theme/density (Rejected)

A single cycle/toggle button suits a 2–3 state switch. Font is **two**
dimensions (family × size) with several options each, which a dropdown in
Settings expresses far better than a header button.

### Keep fonts fixed, no user choice (Rejected)

Simplest, but ignores a real accessibility request (dyslexia-friendly faces,
larger text) that the token architecture makes cheap to grant.

## Consequences

### Positive

- One reference for "why these fonts" and "can fonts be user-chosen".
- No third-party font requests preserved; the dyslexia face costs nothing until
  a user opts in.
- Accessibility improved (size + dyslexia-friendly option) on top of an
  already-accessible default, with no FOUC and no component changes.

### Negative / trade-offs

- The preference is per-device (localStorage), not account-synced.
- `--font-scale` enlarges body/reading text; the server-rendered header chrome
  (its own `header.css`) and a few explicitly-sized controls do not scale.
- Each additional self-hosted family adds `woff2` weight (OpenDyslexic ≈ 115 KB
  per weight) — mitigated by lazy `@font-face` (downloaded only when selected).

## Related

- `templates/partials/theme-init.html.twig`, `density-init.html.twig` — the
  same before-first-paint preference mechanism.
- ADR-012 — performance posture (subsetting, pay-for-what-you-use).
