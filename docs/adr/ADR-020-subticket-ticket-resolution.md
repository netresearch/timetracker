# ADR-020: Jira Subticket Sync and Ticket→Project Resolution

**Status:** Accepted — subtickets are synced from the ticket system (manual admin
action, CLI, or cron) and participate in ticket→project resolution, with an exact
subticket match taking precedence over the `jira_id` prefix rule.
**Date:** 2026-07-04
**Relates to:** [ADR-016](ADR-016-solidjs-frontend-rewrite.md) (the SolidJS rewrite that
dropped the legacy in-app refresh timer), [ADR-017](ADR-017-jira-cloud-oauth2.md) (the
OAuth flow whose per-user token the sync uses), [ADR-005](ADR-005-caching-strategy.md)
(node-local caching — why a scheduled refresh, not a shared push).

## Context

A TimeTracker project binds to Jira through two independent axes:

- **`jira_id`** — a comma/space-separated list of Jira **project prefixes** (e.g.
  `SA, DHLSUP`). When a user types a ticket, its prefix is matched against this list to
  auto-assign the project ([#453](https://github.com/netresearch/timetracker/pull/453)),
  and the same rule validates the ticket on save
  ([`SaveEntryAction::validateTicketPrefix`](../../src/Controller/Tracking/SaveEntryAction.php)).
- **`subtickets`** — a comma-separated list of concrete **ticket keys** synced from Jira:
  the sub-issues of the project's main ticket(s)/epic(s) in `jira_ticket`
  ([`SubticketSyncService`](../../src/Service/SubticketSyncService.php), which walks
  subtasks and epic-linked issues via the project lead's OAuth token).

The gap this ADR closes: the subtickets were **synced and displayed but never used**.
A ticket that belongs to a project only by being one of its synced subtickets — for
example an epic-linked issue that lives in a *different* Jira project, so its prefix is not
in `jira_id` — could not be auto-assigned and was rejected on save. The legacy ExtJS admin
had the sync buttons and a 15-minute in-app timer that reloaded project data "to make
subtickets available"; the SolidJS rewrite carried over neither until
[#546](https://github.com/netresearch/timetracker/pull/546) (the sync UI) and this change
(the resolution wiring).

## Decision

1. **Subtickets participate in ticket→project resolution, and an exact subticket key match
   wins over a prefix match.** A synced subtickets list enumerates specific keys, so it is
   more precise than the project-wide prefix rule; when a typed key is an exact subticket of
   one project and a prefix match of another, the subticket owner is chosen. The frontend
   auto-assign ([`Tracking.tsx`](../../frontend/src/pages/Tracking.tsx)) checks subtickets
   first, then falls back to the `jira_id` prefix. The backend accepts the same on save
   (`SaveEntryAction::isKnownSubticket`), so the client never maps a ticket the server would
   reject.

2. **The subtickets column is refreshed by an explicit sync, not an in-app poller.** Three
   triggers, all calling the one service:
   - the admin **"Sync subtickets"** (per project) and **"Sync all subtickets"** (toolbar)
     actions (`POST /projects/{id}/syncsubtickets`, `POST /projects/syncsubtickets`);
   - the CLI `tt:sync-subtickets [project]`, intended for **cron** (see
     [subticket-sync.md](../subticket-sync.md));
   - an implicit sync when a project is saved
     ([`SaveProjectAction`](../../src/Controller/Admin/SaveProjectAction.php)).
   The legacy 15-minute in-app timer is **not** restored: TanStack Query already refetches
   reference data, a manual refresh exists, and a periodic client poll would re-run the sync
   far more often than the data changes. Freshness is a scheduling concern, handled by cron.

3. **A `projects.subtickets_synced_at` timestamp records the last sync.** Null = never
   synced. It is stamped on every successful sync and surfaced (read-only) in the projects
   admin, so an operator — and the cron documentation — can spot projects whose subtickets
   have gone stale.

## Consequences

- A ticket from another Jira project can be tracked against a project once it appears in
  that project's synced subtickets — the motivating n-Jira-tickets→1-project case — without
  widening the `jira_id` prefix list (which would also admit unrelated tickets).
- The subtickets list is only as fresh as the last sync. Without a cron job it drifts as
  epics gain sub-issues; the timestamp column makes that visible rather than silent.
- Resolution is a two-pass find (subtickets, then prefix) over the in-memory project list —
  negligible for the project counts here, and the backend validation mirrors it exactly so
  the two never disagree.
- The sync needs the project's lead user to hold a valid Jira OAuth token; a project without
  a ticket system, lead, or token returns a 400 (surfaced inline in the admin), unchanged.
