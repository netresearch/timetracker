# ADR-026: Jira/Tempo Project Import with Derived Customers

**Status:** Accepted — 2026-07-12 (live-verified against NR-JIRA)
**Relates to:** [ADR-023](ADR-023-jira-worklog-bidirectional-sync.md) (the worklog import that parks `unresolved_project`), [ADR-020](ADR-020-subticket-ticket-resolution.md) (the `jira_id` prefix → project resolution precedence), [ADR-011](ADR-011-security-architecture.md) (encrypted OAuth credentials).

## Context

The ADR-023 worklog import resolves a Jira issue key to a TT `Project` by matching the key's prefix against `project.jira_id` (or an exact subticket). When no TT project claims a prefix it parks the worklog as `unresolved_project` (`TicketProjectResolver:66`, `ImportWorklogsService:290-304`) and the work is never imported. A real run surfaced this at scale — prefixes like `SRVMO`, `SRVACME`, `JOT` have no TT project, and `NRFE` is **ambiguous** (three projects claim it).

Creating those TT projects by hand is the blocker, and the friction is the **Customer**: every TT `Project` needs a `Customer` FK (`Project.php:46`), but a Jira project carries no customer concept, so an admin must invent the mapping each time. That manual mapping is exactly what makes bulk onboarding painful and error-prone.

**Feasibility confirmed (2026-07-12):** NR-JIRA is Jira **Server 9.12.3**; **Tempo Timesheets is installed** (`/rest/tempo-accounts/1/account` returns 401, i.e. present, not 404). Tempo on Server/DC accepts the **same Jira OAuth1 token** TT already holds, so no new credential is needed. Each Tempo **Account** carries a `customer` object (`{key, id, name}`) plus a `category` and `lead`; a project's usable accounts are readable via `/rest/tempo-accounts/1/account/project/{projectId}`. Jira's own `/rest/api/2/project/{key}` returns `projectCategory` as a fallback.

## Decision

### 1. Derive the Customer, don't ask for it

For each Jira project being imported, derive the TT `Customer` automatically, in precedence order:

1. **Tempo Account customer** — the `customer` on the project's linked Tempo Account (Tempo Accounts are the billing/customer entity). Primary source.
2. **Jira project category** — `projectCategory.name` when no Tempo customer resolves.
3. **Keyword rule** — a configured mapping from a project-key/name keyword to a customer, as the last resort.

The imported `Project` gets `jira_id` = the Jira project key (prefix), `ticketSystem` = the import's system, `name` = the Jira project name, and `customer` = the derived Customer.

### 2. Ambiguity is parked, never guessed — but a default account disambiguates

A prefix already claimed by several TT projects (the `NRFE` case), or a Jira project linked to several Tempo accounts resolving to **several distinct customers**, is **surfaced for a human decision** — mirroring `TicketProjectResolver::decide()` (one candidate → resolve, several → park). The import never auto-picks among genuinely competing candidates.

One narrowing: a project's Tempo links carry a `defaultAccount: true` flag (`/rest/tempo-accounts/1/link/project/{id}`). When several accounts link but exactly one is the default, the default account's customer is the confident pick; only when the customers still differ with no single default does it park. (Live data: `NRFE` links 4 accounts spanning 2 customers → park unless a default resolves it; `SRVMO`/`SRVACME` link 1 → clean.)

### 3. Tempo access reuses the Jira OAuth1 token

A `TempoClient` (sibling of the Jira services under `src/Service/Integration/Jira/`) signs requests with the existing OAuth1 machinery of `JiraOAuthApiService` (`getClient()` signs by host), reaching `/rest/tempo-accounts/1/…` on the same tenant. No new credential, no new admin config. Visibility is bounded by the token owner's Tempo permissions — see §6.

### 4. Phasing

- **P1 — manual review-and-confirm screen (no schema change).** An admin/PO action lists the `unresolved_project` prefixes from recent sync runs; for each, TT calls Jira (category) + Tempo (account→customer) and **proposes** a derived Customer + Project; the human confirms or overrides per row before anything is persisted. Customer is matched to an existing one **by name** initially (no new column). This validates the derivation quality against real data with zero risk of wrong auto-created customers.
- **P2 — stable customer key.** Add `customers.tempo_customer_key` (nullable, unique) so a Customer upsert is idempotent across runs; backfill from P1 confirmations. Without a stable key, name-drift spawns duplicate customers.
- **P3 — ad-hoc auto-create during import.** Only once P1 shows the derivation is reliable: `ImportWorklogsService::resolveProject()` (today it returns null and parks) calls a `ProjectImportService` that auto-creates Project+Customer **only when exactly one confident Customer is derivable**, and continues to **park** on ambiguity, missing Tempo data, or a bare keyword guess. Never silently auto-create a Customer from a low-confidence keyword.

## Alternatives considered

- **Bulk-import all Jira projects** under one chosen customer or a placeholder: fast but pollutes the customer list and mis-bills; rejected in favour of per-project derived customers with human confirmation (P1).
- **Manual project creation only** (no derivation): the status quo; rejected because the Customer-mapping friction is the whole problem.
- **Auto-create from day one** (skip P1): rejected — deriving a wrong Customer silently corrupts billing data (blast radius: money). Auto-create is gated behind P2's stable key and P3's confidence check.
- **A new Tempo admin credential/config** (like the Personio config): unnecessary — Tempo Server/DC accepts the existing Jira OAuth1 token; adding a credential would duplicate ADR-024's config surface for no gain.

## Consequences

- A new `TempoClient` read layer and a `ProjectImportService`; P2 adds one nullable `customers` column; no change to the worklog reconciliation engine.
- Billing correctness depends on the derived Customer being right — hence P1's human confirmation gate before any auto-create, and P3's "one confident customer or park".
- The import path (P3) gains the ability to self-heal `unresolved_project` prefixes, shrinking the parked-worklog backlog over time.
- A second consumer of the Jira OAuth token (after worklog sync) begins to shape a Tempo integration surface; kept minimal (read-only accounts/links) until a third need appears (YAGNI).

## Verification — resolved against live NR-JIRA (2026-07-12)

All five points were checked with a real read-only Jira PAT; the design holds:

1. **Token permission — 200 (readable).** `GET /rest/tempo-accounts/1/account` returned 200 with a read-only token (155 accounts) — **not** admin-gated here. Approach for the app: try under the PO token, fall back to category/keyword on a 403.
2. **Account JSON shape — confirmed.** `customer` = `{id, key, name}` (e.g. `{"id":10,"key":"DP","name":"Deutsche Post AG"}`); `category` = `{id, key, name, categorytype{…}}`. The stable **`customer.key`** is the P2 idempotent upsert key; `customer.name` seeds the TT Customer name.
3. **Data quality — fallback is mandatory, not optional.** 138/155 accounts carry a customer, but **per project it varies**: `SRVMO`/`SRVACME` link one account (customer *Netresearch* [NR]); **`JOT` links zero accounts** → derivation MUST fall back to its `projectCategory` (*NR: IT*). So the category/keyword fallback is a first-class path, not an edge case.
4. **Cardinality — ambiguity is real.** `NRFE` links **4 accounts across 2 customers** (Netresearch [NR] + Netresearch Solutions [NRSO]) → park (or resolve via `defaultAccount`, §2). Single-account projects resolve cleanly.
5. **Key→id + category.** `GET /rest/api/2/project/{key}` yields the numeric `id` (JOT 23050, NRFE 10212, SRVMO 20350, SRVACME 17250) **and** a non-null `projectCategory` on all four — feeding both the link lookup (`/account/project/{id}` or `/link/project/{id}`) and the fallback.

The `TempoClient` unit tests fixture these confirmed shapes; a thin integration test can re-run the live probe when a token is configured.
