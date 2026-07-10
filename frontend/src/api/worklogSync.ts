import { getJson, postJson } from './client'

// ── Worklog-sync DTOs (ADR-023 §6) ───────────────────────────────────────────
// Snake_case, mirroring the Phase 4a backend DTOs (SyncRunDto / SyncRunItemDto /
// SyncConflictDto) the v2 endpoints serialize.

/** One provenance row of a run (SyncRunItemDto) — present only on the detail view. */
export interface SyncRunItem {
  kind: string
  issue_key: string | null
  remote_worklog_id: number | null
  entry_id: number | null
  author: string | null
  reason: string
  payload: Record<string, unknown> | null
  created_at: string
}

/** A sync run (SyncRunDto). `items` is populated only on the single-run detail. */
export interface SyncRun {
  id: number
  type: string
  status: string
  ticket_system_id: number | null
  triggered_by: string | null
  scope: Record<string, unknown>
  counters: Record<string, number>
  started_at: string | null
  finished_at: string | null
  items?: SyncRunItem[]
}

/** An unresolved sync conflict (SyncConflictDto): the local entry vs the remote
 *  worklog, plus the base payload both diverged from. `conflict_remote` is null
 *  when the remote worklog was deleted (orphaned). */
export interface SyncConflict {
  id: number
  status: string
  entry: {
    id: number
    user: string | null
    ticket: string
    day: string
    start: string
    end: string
    duration: number
    description: string
  }
  base_payload: Record<string, unknown>
  base_updated_at: string
  conflict_remote: {
    comment: string | null
    started: string | null
    timeSpentSeconds: number | null
    updated: string | null
  } | null
  last_synced_at: string | null
}

/** Body for POST /api/v2/worklog-sync/runs. */
export interface CreateRunPayload {
  type: 'verify' | 'import' | 'sync'
  ticket_system_id: number
  from?: string
  to?: string
  users?: string[]
  default_activity_id?: number
  dry_run?: boolean
  since?: string
}

/** Base cache keys — callers invalidate `worklogSyncKeys.runs` / `.conflicts`
 *  after a write; the per-query factories append their params/id under them. */
export const worklogSyncKeys = {
  runs: ['worklog-sync', 'runs'] as const,
  conflicts: ['worklog-sync', 'conflicts'] as const,
}

// ── Query factories ({queryKey, queryFn}, per the src/api/queries.ts convention) ─

/** GET /api/v2/worklog-sync/runs — run history (no items), filtered/limited. */
export function syncRunsQuery(ticketSystemId?: number, limit?: number) {
  const params: Record<string, number> = {}
  if (ticketSystemId !== undefined) {
    params.ticket_system_id = ticketSystemId
  }
  if (limit !== undefined) {
    params.limit = limit
  }

  return {
    queryKey: [...worklogSyncKeys.runs, params] as const,
    queryFn: () => getJson<{ runs: SyncRun[]; count: number }>('/api/v2/worklog-sync/runs', params),
  }
}

/** GET /api/v2/worklog-sync/runs/{id} — a single run with its items. */
export function syncRunQuery(id: number) {
  return {
    queryKey: [...worklogSyncKeys.runs, id] as const,
    queryFn: () => getJson<SyncRun>(`/api/v2/worklog-sync/runs/${id}`),
  }
}

/** GET /api/v2/worklog-sync/conflicts — unresolved conflicts, optionally scoped. */
export function syncConflictsQuery(user?: string) {
  const params: Record<string, string> = {}
  if (user !== undefined) {
    params.user = user
  }

  return {
    queryKey: [...worklogSyncKeys.conflicts, params] as const,
    queryFn: () => getJson<{ conflicts: SyncConflict[]; count: number }>('/api/v2/worklog-sync/conflicts', params),
  }
}

// ── Write helpers (plain async; the caller invalidates the matching key) ──────

/** POST /api/v2/worklog-sync/runs — trigger a run; returns the created SyncRun. */
export function createSyncRun(payload: CreateRunPayload): Promise<SyncRun> {
  return postJson<SyncRun>('/api/v2/worklog-sync/runs', { ...payload })
}

/** POST /api/v2/worklog-sync/conflicts/{id}/resolve — keep the local or remote side. */
export function resolveConflict(
  id: number,
  winner: 'local' | 'remote',
): Promise<{ resolved: boolean; action: string; conflict_id: number }> {
  return postJson<{ resolved: boolean; action: string; conflict_id: number }>(
    `/api/v2/worklog-sync/conflicts/${id}/resolve`,
    { winner },
  )
}
