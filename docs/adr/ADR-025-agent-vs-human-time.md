# ADR-025: Agent vs. Human Time Attribution

**Status:** Proposed — 2026-07-11
**Relates to:** [ADR-023](ADR-023-jira-worklog-bidirectional-sync.md) (accountability under a responsible person's token — reused here as agent→human attribution), [ADR-024](ADR-024-personio-attendance-absence-sync.md) (Personio attendance; the ArbZG boundary this ADR must not cross).

## Context

Coding agents now perform a growing share of the work TimeTracker records, and their logged time **overlaps** — by nature. A person supervising agents parallelises: several agents run at once, and while they run the person does other things. A naive worklog treats every logged hour as human labour, so agent time corrupts every metric it touches: a person "logs" 14 h in an 8 h day, capacity planning sees phantom headcount, and — the hard limit — **attendance/working-time-law (ArbZG) records inflate past legal bounds**.

The agent's wall-clock time must still be **recorded completely** (for controlling, billing, and seeing where effort actually goes), but it is a *different resource* from human labour and must not be summed into it.

Three quantities were being conflated in one `duration` field and must be separated:

1. **Human labour** — attention/effort a person spends. Bounded by their day, cannot overlap for one person, feeds attendance/ArbZG and billing.
2. **Agent wall-clock** — how long a machine ran. Can overlap freely, unbounded relative to a person's day; a machine-resource metric, not labour.
3. **Attribution** — which person is *responsible* for an agent's work (accountability), distinct from who *performed* the labour.

**A rejected framing:** having the agent emit a "human share" coefficient (e.g. "20 % human"). The agent has no visibility into the human's parallel effort, so any such number is invented — indefensible in a billing audit. The agent can state facts about *itself* (its wall-clock, its touchpoints with a human), never quantify the human's effort.

## Decision

### 1. A `source` dimension on every entry

`entries.source` enum (`human` | `agent`, default `human`). Existing rows are `human` — additive, backward-compatible. Every downstream rule keys off this one column.

### 2. The agent logs *both* streams — the human delegates

A person who runs agents will **not** book time manually; they delegate logging to the agent, which is the interface and holds the interaction. So the agent creates BOTH:

- a `source=agent` entry for its own **wall-clock** (a hard fact), and
- a `source=human` entry for the **human's own involved time** — the steering, review and decision effort the person actually spent.

`source` denotes *who performed the labour*, not who typed the record. A separate `logged_by` captures the actual author (the agent, for both of the above). Existing UI-entered worklogs are `source=human, logged_by=<the user>`. The human/agent **share** is then derived from the two entries (`human_minutes / (human_minutes + agent_minutes)`).

### 3. The human's time is a derived estimate from observed interaction facts — and always overridable

The agent must **not** invent a "human share" coefficient. It derives the human's involved minutes from **observable interaction signals it actually holds**: the timestamps of the human's turns, time spent in review/approval, and the touchpoint counts (`prompts`, `reviews`, `interventions`, extensible). The `source=human` entry the agent creates carries those signals plus an `estimated=true` flag and the derivation basis — so the figure is an **auditable estimate**, never a hidden fudge. This is the honest form of "the agent splits human vs agent": it splits from facts it observes and marks the uncertainty, rather than asserting an effort it cannot measure. The responsible human can **correct** any agent-logged entry after the fact; the estimate is the default precisely because they usually won't touch it.

### 4. Attribution: responsible human on agent entries

Every `source=agent` entry references the **responsible** user (who commissioned/owns the run) — the same accountability primitive as the ADR-023 sync opt-in (work is *attributable* to a person without being *performed* by them). "Who, and at what share" is thereby stored: responsible person + the two-stream split.

### 5. ArbZG / attendance: human-source only

Attendance and any working-time-law computation (incl. the ADR-024 Personio export) draw **exclusively** from `source=human` time. `source=agent` time never contributes. This is what makes agent overlap harmless — confirmed as the governing rule: only the human's own time is legally relevant. Because the `source=human` figure may be an agent-derived estimate (§3), it must stay **visible and correctable** by the person before it hardens into an attendance record — the `estimated` flag surfaces exactly which entries a human should still eyeball for legal defensibility.

### 6. Overlap validation split by source

- `source=human` entries keep the non-overlap invariant (a person's real day cannot double-book).
- `source=agent` entries are **exempt** from the overlap check (parallel agents, and overlap with the human's own work are expected).

### 7. Controlling & billing: slice, never sum across sources

Reports expose the axes independently: **wall-clock (agent)**, **human portion**, **agent portion**, **source**, and **responsible person**. Human hours are billable labour (or per contract); agent hours are a **separate** metric (machine time) with their own rate, billed only where a contract permits — never rolled into the human labour line.

## Alternatives considered

- **Mark agent time but let it overlap in the same ledger:** the `mark` (source dimension) is kept; the "same ledger" is rejected — it only exempts the overlap check while leaving agent time summable into human hours/attendance (the ArbZG failure).
- **Agent-supplied human-share coefficient (single entry, embedded %):** matches the initial intuition but rejected — a flat coefficient the agent cannot measure is fiction; fails an audit. Chosen instead: the agent logs *both* entries and derives the human-source minutes from **observed interaction facts** (timestamps, review duration, touchpoints), flagged `estimated` and correctable.
- **Human logs their own time manually as the primary path:** rejected — a person who runs agents delegates logging entirely and won't book manually; the manual path survives only as an *override* on agent-created entries.
- **Fully separate agent-time table:** clean isolation but duplicates the entry/reporting/attribution machinery; the `source` dimension on the existing entry achieves the separation with far less surface.

## Consequences

- New `entries` fields: `source` (human|agent), `logged_by` (actual author), `estimated` (bool), a responsible-user FK, and touchpoint/interaction-signal counts. All additive; existing worklogs default to `source=human, logged_by=owner, estimated=false`.
- Every aggregation (attendance, day classes, exports, controlling) must be audited to filter/branch on `source` — the main implementation cost; a missed spot is how agent time would leak into human totals.
- Overlap validation becomes source-aware.
- The Jira/Personio sync paths (ADR-023/024) must tag imported/exported time with the correct source and never export `source=agent` time as attendance.
- A clean basis for AI-ROI reporting (agent hours vs. delivered work) falls out for free, without polluting labour metrics.

## Verification points before implementation

1. Ingest surface: the agent creates BOTH the `source=agent` (wall-clock) and the delegated `source=human` (estimated) entry via the v2 API (ADR-022) under its PAT (ADR-021). Confirm the payload shape — `source`, `logged_by`, responsible user, touchpoint counts, `estimated` flag — and that an agent PAT is permitted to write a `source=human` entry attributed to its responsible user.
2. Exact touchpoint/interaction-signal set worth capturing (turn timestamps / review duration / prompts / reviews / interventions / tokens?) and whether the agent reliably holds them at log time to derive the human-minutes estimate.
3. Every current consumer of entry durations (attendance, `DayClassService`, controlling/export, capacity) enumerated and branched on `source` — the completeness gate.
4. Billing contract basis: are agent hours billable at all, and at what rate/line item? (Reporting must not imply billability that contracts don't grant.)
