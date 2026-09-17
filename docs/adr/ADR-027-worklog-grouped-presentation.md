# ADR-027: Worklog Grouped Presentation with Composite Cells

**Status:** Accepted — 2026-09-17 (shipped in #707/#708/#709, live)
**Relates to:** [ADR-025](ADR-025-agent-vs-human-time.md) (a human entry and its agent walltime render as one row, their figures never summed), [ADR-022](ADR-022-v2-api-layer-and-response-dtos.md) (the rows both views render come from the same payload)

## Context

The worklog was one flat grid: eight columns, one row per entry, every value repeated on every row. Down a screen of twenty entries the same customer, project and activity are printed twenty times, while what actually varies — the time, what was done, how long it took — competes with that repetition for attention. A design review of the grid asked for a presentation that states shared context once and gives the varying part the room.

Two constraints shaped what could be built:

1. **`gridNavigation.ts` addresses cells by `cellIndex`.** Any layout that merges cells with `rowspan` breaks the roving-tabindex arithmetic that the whole keyboard model rests on, and that model is not negotiable (WCAG 2.2 AA plus the documented AAA subset).
2. **Both presentations must render the same row.** Two render paths for one grid means inline editing, the row cues and the save paths drift apart — the defect class this project has paid for before.

## Decision

**A grouped presentation becomes the default; the flat grid stays, one switch away.** Both render through the same `renderRow()`.

**Cards and blocks.** Rows sit in cards, one `<tbody>` each. The chosen ORDER decides what a card is: ordered by time a card is a **day**; ordered by customer a card is a **customer and project**. Inside a card, consecutive rows that share the dimension the card does not name form a **block** — the context in a day card, the day in a customer card. A block prints its shared values once, on its first row, and closes with an accent line under its last row.

**Composite cells instead of `rowspan`.** The grouped view has four columns — block context, `09:00–10:30`, description with its ticket, duration — each a single `<td>` holding several fields. `cellIndex` arithmetic is untouched. Each field inside a cell is its own edit target, and the shared edit controller is told what a cell holds (`cellFields(colKey, rowId)`), so Tab, Enter's guided fill and the activation path walk fields rather than columns. A cell that shows nothing on a given row (a block continuation) declares no fields and is skipped.

**Colour means one thing per view.** The flat grid keeps the entry-class cues (day break, break, overlap) as row borders. In the grouped view a day break cannot occur inside a day card, so the accent line marks a block's end instead; the break and overlap cues stop at the block column so they cannot cut a block in two; and ordered by customer the cues are withheld entirely, because they assert adjacency in time and the row above is no longer the minute before.

**The duration bar is scaled to the longest entry on screen, with one hour as the floor.** A fixed scale drew every entry past its cap as the same full bar, which is the one comparison a bar exists to make. The floor keeps short days looking the same from one day to the next. The figure beside the bar always states the exact value; the bar is decorative and hidden from assistive technology.

**Preferences are per browser.** View, order and day range live in `localStorage`, like the theme and the navigation layout — they are view preferences, not account data. An unknown stored value falls back to the default rather than rendering nothing.

## Consequences

- The grid has one more axis for a reader to learn (view × order), and the toolbar carries two more controls. They are one `SegmentedSwitch` component: one tab stop, arrow keys with wrap.
- A field that is not a column of its own is only reachable through the controller's field list. Anything addressing the grid's cursor must map a field to its CELL (`cellKeyForField`), or the cursor is left behind — a defect that shipped once and cost the keyboard path in a new row.
- Sticky headers need a scrolling ancestor, so the grouped grid scrolls inside a box whose height is measured from its own position to the bottom of the viewport. A fixed offset cannot know where the grid starts, which differs by navigation layout.
- Three presentations were built and one was dropped: a duration-proportional timeline fought inline editing (an open editor resizes its own row) and is better rethought as an overview. A stored `'timeline'` preference falls back to the default.
- Because the two views differ in markup, e2e specs must say which one they are in; the suite seeds the flat view for every context (see `e2e/AGENTS.md`).

## Alternatives considered

- **`rowspan` for the shared context.** Rejected: it breaks `cellIndex` and with it the keyboard model. The block column prints on the first row and leaves the rest blank with a suppressed border, which reads as one area without lying to the DOM.
- **Grouping only by day.** Rejected after use: a day's work on one thing is often scattered across the day, and the customer order is what makes that legible. Grouping by day only would have kept the redesign's benefit for calendar reading and lost it for context reading.
- **Server-side preferences.** Deferred: `/api/v2/settings` could carry the view, which would follow a user across devices. Kept client-side for now; the trade is that a new browser starts at the default.
