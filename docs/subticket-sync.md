# Jira Subticket Sync

TimeTracker can pull the **sub-issues** of a project's Jira epic(s)/main ticket(s) into
the project and use them to resolve tracked tickets to that project — even when a ticket
lives in a different Jira project. See
[ADR-020](adr/ADR-020-subticket-ticket-resolution.md) for the design rationale.

## What it does

For a project that has a ticket system, a **main ticket** (`jira_ticket`, e.g. an epic key
or a comma-separated list), and a **project lead** with a valid Jira OAuth token, the sync
walks each main ticket's subtasks and epic-linked issues via the Jira API and stores the
resulting ticket keys in the project's `subtickets` field (comma-separated). It also stamps
`subtickets_synced_at`.

Those keys then drive ticket→project resolution when a user types a ticket in the work-log:

- An **exact subticket match wins** — a ticket listed in a project's subtickets is assigned
  to that project even if its prefix is not in the project's `jira_id`.
- Otherwise the usual **`jira_id` prefix** rule applies.

The backend validates saves the same way, so a ticket the UI auto-assigns is never rejected.

## Configure a project

In **Administration → Projects**, a project must have:

1. a **Ticket system** (the Jira instance);
2. a **Jira ticket** (`jira_ticket`) — the epic/main issue whose sub-issues to collect;
3. a **Project lead** who has authorized Jira via OAuth ([ADR-017](adr/ADR-017-jira-cloud-oauth2.md)),
   because the sync uses that user's token.

Missing any of these makes the sync return `400` (shown inline in the admin).

## Trigger a sync

- **Admin UI** — the Projects grid has a per-row **Sync subtickets** button and a
  **Sync all subtickets** toolbar button. The **Subtickets synced** column shows when each
  project was last refreshed (`—` = never).
- **On project save** — saving a project re-syncs it implicitly.
- **CLI** — for scheduled refreshes:

  ```bash
  # All projects
  docker compose exec app php bin/console tt:sync-subtickets

  # A single project by id
  docker compose exec app php bin/console tt:sync-subtickets 42
  ```

  (Use the service/container name for your environment, e.g. `app-dev` in the dev profile.)

## Keep it fresh with cron

There is **no in-app polling** — the subtickets list is only as current as the last sync.
Schedule the CLI command to keep it fresh. The `subtickets_synced_at` column makes staleness
visible in the admin.

**Host crontab** (runs the command inside the running `app` container nightly at 03:17):

```cron
17 3 * * * cd /srv/timetracker && docker compose exec -T app php bin/console tt:sync-subtickets >> /var/log/tt-subticket-sync.log 2>&1
```

**systemd timer** (alternative): a `tt-subticket-sync.service` running the same
`docker compose exec -T app php bin/console tt:sync-subtickets`, plus a
`tt-subticket-sync.timer` with `OnCalendar=*-*-* 03:17:00`.

Pick a cadence that matches how often your epics gain sub-issues — nightly is usually
enough. The command is idempotent: it overwrites each project's subtickets with the current
Jira state, so re-running it is safe.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `400 No ticket system configured for project` | The project has no ticket system. |
| `400 Project has no lead user` | Set a project lead. |
| `400 Project user has no token for ticket system` | The lead must authorize Jira via OAuth. |
| Subtickets empty after sync | The project's `jira_ticket` is unset, or the epic has no sub-issues. |
| A ticket still isn't auto-assigned | It is neither in a project's subtickets nor matches a `jira_id` prefix — sync the owning project, or add the prefix. |
