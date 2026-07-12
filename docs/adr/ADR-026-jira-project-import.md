# ADR-026: Jira/Tempo Project Import with Derived Customers

**Status:** Proposed — 2026-07-12
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

### 2. Ambiguity is parked, never guessed

A prefix already claimed by several TT projects (the `NRFE` case), or a Jira project linked to several Tempo accounts/customers, is **surfaced for a human decision** — mirroring `TicketProjectResolver::decide()` (one candidate → resolve, several → park). The import never auto-picks among candidates.

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

## Verification points before/at implementation

Feasibility confirmed Tempo is installed and the token is reusable; the following need a live call **under a real PO token** — the `TempoClient`'s integration test against NR-JIRA IS this verification, run first in P1:

1. **PO-token permission:** does the PO's OAuth token authorise `/rest/tempo-accounts/1/account` (200) or is Browse-Accounts admin-gated (403)? A 403 forces an admin/service token or degrades every derivation to the category/keyword fallback.
2. **Exact account JSON shape** (`customer`/`category` field names, nesting) — pin against a live response, not the secondary-source docs.
3. **Data quality:** what fraction of projects have a linked account *with* a customer (else the fallback carries the load).
4. **Cardinality:** how often one project maps to several accounts/customers (→ must park, not pick).
5. **Key→id:** the link endpoint takes Jira's numeric `projectId`, not the key — resolve via `/rest/api/2/project/{key}` (which also yields `projectCategory`).
