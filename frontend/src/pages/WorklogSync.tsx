import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import { ticketSystemsQuery, usersQuery } from '../api/queries'
import {
  createSyncRun,
  syncRunQuery,
  syncRunsQuery,
  worklogSyncKeys,
  type CreateRunPayload,
  type SyncRun,
} from '../api/worklogSync'
import { ConflictList } from '../components/ConflictList'
import { DateField } from '../components/DateField'
import { SyncRunSummary } from '../components/SyncRunSummary'
import { hasRole } from '../config'
import { isoDate } from '../lib/format'
import { m } from '../paraglide/messages.js'

const RUN_TYPES = ['verify', 'import', 'sync'] as const
type RunType = (typeof RUN_TYPES)[number]

const typeLabels: Record<RunType, () => string> = {
  verify: m.worklogsync_type_verify,
  import: m.worklogsync_type_import,
  sync: m.worklogsync_type_sync,
}
const typeLabel = (type: string): string => (typeLabels[type as RunType] ?? (() => type))()

// Status value → localized badge label; an unknown status falls back to its raw
// value (paired with the is-<status> class) so a future backend status still reads.
const statusLabels: Record<string, () => string> = {
  running: m.worklogsync_status_running,
  partial: m.worklogsync_status_partial,
  completed: m.worklogsync_status_completed,
  failed: m.worklogsync_status_failed,
}
const statusLabel = (status: string): string => (statusLabels[status] ?? (() => status))()

// The default trigger window is the current month up to today — the common case.
function firstOfMonth(): string {
  const now = new Date()

  return isoDate(new Date(now.getFullYear(), now.getMonth(), 1))
}

function formatDate(iso: string | null): string {
  if (!iso) {
    return '—'
  }
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}

// A compact one-line counters summary for the history row (the full breakdown is
// in the run detail's SyncRunSummary). Raw keys — the terse row favours density.
function countersSummary(counters: Record<string, number>): string {
  const entries = Object.entries(counters)
  if (entries.length === 0) {
    return '—'
  }

  return entries.map(([key, value]) => `${key}: ${value}`).join(', ')
}

/**
 * The admin worklog-sync area (ADR-023 §6): trigger a run, browse the run
 * history (with per-run detail), and resolve conflicts. Rendered as a non-CRUD
 * Administration sub-page (see Admin.tsx), so it already sits behind the
 * ROLE_ADMIN route guard; the in-component gate keeps a direct render honest.
 */
export default function WorklogSync(): JSX.Element {
  return (
    <Show when={hasRole('ROLE_ADMIN')}>
      <WorklogSyncArea />
    </Show>
  )
}

function WorklogSyncArea(): JSX.Element {
  const queryClient = useQueryClient()
  const ticketSystems = useQuery(ticketSystemsQuery)

  // Trigger form.
  const [type, setType] = createSignal<RunType>('verify')
  const [ticketSystemId, setTicketSystemId] = createSignal(0)
  const [from, setFrom] = createSignal(firstOfMonth())
  const [to, setTo] = createSignal(isoDate(new Date()))
  const [selectedUsers, setSelectedUsers] = createSignal<string[]>([])
  const [dryRun, setDryRun] = createSignal(true)
  const [busy, setBusy] = createSignal(false)
  const [error, setError] = createSignal('')
  const [result, setResult] = createSignal<SyncRun | null>(null)

  // Run history: an optional ticket-system filter + the run opened for detail.
  const [historyFilter, setHistoryFilter] = createSignal(0)
  const [selectedRunId, setSelectedRunId] = createSignal<number | null>(null)

  // TT users for the target dropdown (empty selection = all users).
  const userOptions = useQuery(() => usersQuery())

  const runs = useQuery(() => syncRunsQuery(historyFilter() > 0 ? historyFilter() : undefined))
  const runDetail = useQuery(() => ({
    ...syncRunQuery(selectedRunId() ?? 0),
    enabled: selectedRunId() !== null,
  }))

  const canRun = (): boolean => ticketSystemId() > 0 && !busy()

  function buildPayload(): CreateRunPayload {
    // A sync run targets at most one user; verify/import accept several.
    const users = type() === 'sync' ? selectedUsers().slice(0, 1) : selectedUsers()

    return {
      type: type(),
      ticket_system_id: ticketSystemId(),
      from: from(),
      to: to(),
      ...(users.length > 0 ? { users } : {}),
      dry_run: dryRun(),
    }
  }

  async function trigger(): Promise<void> {
    setBusy(true)
    setError('')
    try {
      const run = await createSyncRun(buildPayload())
      setResult(run)
      // A new run belongs at the top of the history — refresh it.
      void queryClient.invalidateQueries({ queryKey: worklogSyncKeys.runs })
    } catch (caught) {
      setError(apiErrorMessage(caught, m.worklogsync_run_error()))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div class="worklog-sync">
      {/* Region 1 — trigger a run. */}
      <section class="status-group">
        <h2 class="status-group-title">{m.worklogsync_trigger()}</h2>
        <div class="stack-form">
          <div class="security-block">
            <label class="field">
              <span>{m.worklogsync_type()}</span>
              <select
                value={type()}
                disabled={busy()}
                onChange={(event) => setType(event.currentTarget.value as RunType)}
              >
                <For each={RUN_TYPES}>{(runType) => <option value={runType}>{typeLabels[runType]()}</option>}</For>
              </select>
            </label>

            <label class="field">
              <span>{m.worklogsync_ticket_system()}</span>
              <select
                id="worklogsync-trigger-ticket"
                value={ticketSystemId()}
                disabled={busy()}
                onChange={(event) => setTicketSystemId(Number(event.currentTarget.value))}
              >
                <option value={0}>—</option>
                <For each={ticketSystems.data ?? []}>{(option) => <option value={option.id}>{option.label}</option>}</For>
              </select>
            </label>

            <label class="field">
              <span>{m.worklogsync_from()}</span>
              <DateField value={from()} onChange={setFrom} disabled={busy()} calendar />
            </label>

            <label class="field">
              <span>{m.worklogsync_to()}</span>
              <DateField value={to()} onChange={setTo} disabled={busy()} calendar />
            </label>

            <label class="field">
              <span>{m.worklogsync_users()}</span>
              <select
                multiple={type() !== 'sync'}
                disabled={busy()}
                onChange={(event) => {
                  // A sync run targets a single user (the backend rejects >1 with 422);
                  // verify/import accept a multi-selection.
                  if (type() === 'sync') {
                    setSelectedUsers(event.currentTarget.value ? [event.currentTarget.value] : [])
                  } else {
                    setSelectedUsers(Array.from(event.currentTarget.selectedOptions, (option) => option.value))
                  }
                }}
              >
                <Show when={type() === 'sync'}>
                  <option value="" selected={selectedUsers().length === 0}>—</option>
                </Show>
                <For each={userOptions.data ?? []}>
                  {(option) => (
                    <option value={String(option.label)} selected={selectedUsers().includes(String(option.label))}>
                      {option.label}
                    </option>
                  )}
                </For>
              </select>
              <small class="field-hint">{m.worklogsync_users_hint()}</small>
            </label>

            <label class="field-check">
              <input
                type="checkbox"
                checked={dryRun()}
                disabled={busy()}
                onInput={(event) => setDryRun(event.currentTarget.checked)}
              />
              <span>{m.worklogsync_dryrun()}</span>
            </label>

            <div class="form-actions">
              <button type="button" class="primary-button" disabled={!canRun()} onClick={() => void trigger()}>
                {busy() ? m.app_saving() : m.worklogsync_trigger()}
              </button>
            </div>

            <Show when={result()}>
              {(run) => (
                <div class="sync-result">
                  <p role="status" class="form-status is-ok">{statusLabel(run().status)}</p>
                  <SyncRunSummary run={run()} />
                </div>
              )}
            </Show>

            <Show when={error()}>
              <span role="alert" class="form-status is-error">{error()}</span>
            </Show>
          </div>
        </div>
      </section>

      {/* Region 2 — run history, with per-run detail. */}
      <section class="status-group">
        <h2 class="status-group-title">{m.worklogsync_run_history()}</h2>
        <div class="stack-form">
          <label class="field">
            <span>{m.worklogsync_ticket_system()}</span>
            <select
              id="worklogsync-history-ticket"
              value={historyFilter()}
              onChange={(event) => setHistoryFilter(Number(event.currentTarget.value))}
            >
              <option value={0}>—</option>
              <For each={ticketSystems.data ?? []}>{(option) => <option value={option.id}>{option.label}</option>}</For>
            </select>
          </label>
        </div>

        <Show
          when={(runs.data?.runs.length ?? 0) > 0}
          fallback={<p class="sync-counters-empty">{m.worklogsync_no_runs()}</p>}
        >
          <table class="data-table">
            <thead>
              <tr>
                <th scope="col">{m.worklogsync_type()}</th>
                <th scope="col">{m.worklogsync_run_status()}</th>
                <th scope="col">{m.worklogsync_triggered_by()}</th>
                <th scope="col">{m.worklogsync_run_started()}</th>
                <th scope="col">{m.worklogsync_run_counters()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={runs.data?.runs ?? []}>
                {(run) => (
                  <tr aria-current={selectedRunId() === run.id ? 'true' : undefined}>
                    <td>
                      <button
                        type="button"
                        class="link-button"
                        aria-label={`${m.worklogsync_view_run()}: ${typeLabel(run.type)} ${formatDate(run.started_at)}`}
                        onClick={() => setSelectedRunId(run.id)}
                      >
                        {typeLabel(run.type)}
                      </button>
                    </td>
                    <td>
                      <span class={`sync-status is-${run.status}`}>{statusLabel(run.status)}</span>
                    </td>
                    <td>{run.triggered_by ?? '—'}</td>
                    <td>{formatDate(run.started_at)}</td>
                    <td>{countersSummary(run.counters)}</td>
                  </tr>
                )}
              </For>
            </tbody>
          </table>
        </Show>

        <Show when={selectedRunId() !== null && runDetail.data}>
          {(run) => (
            <div class="sync-run-detail">
              <SyncRunSummary run={run()} />
            </div>
          )}
        </Show>
      </section>

      {/* Region 3 — conflict resolution (ADR-023 §2). Admins see every user's
          parked conflicts here (ConflictList defaults to all when no user filter). */}
      <section class="status-group">
        <h2 class="status-group-title">{m.worklogsync_conflicts_title()}</h2>
        <ConflictList />
      </section>
    </div>
  )
}
