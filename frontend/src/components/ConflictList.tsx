import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import { refreshEntriesAndWorktime } from '../api/queries'
import { resolveConflict, syncConflictsQuery, worklogSyncKeys, type SyncConflict } from '../api/worklogSync'
import { m } from '../paraglide/messages.js'

// Jira reports remote effort in seconds; the local entry stores minutes, so the
// two sides are compared in minutes.
function toMinutes(seconds: number | null): number {
  return seconds === null ? 0 : Math.round(seconds / 60)
}

/**
 * Conflict-resolution screen (ADR-023 §2). Lists the unresolved sync conflicts —
 * each a side-by-side comparison of the local entry against the remote worklog —
 * and lets the operator keep one side. A resolve posts to the backend, then
 * invalidates the conflicts cache so the resolved row drops out on the refetch.
 * Embedded by the admin sync area; `user` scopes it to one person's conflicts.
 */
export function ConflictList(props: { user?: string } = {}): JSX.Element {
  const queryClient = useQueryClient()
  const conflicts = useQuery(() => syncConflictsQuery(props.user))
  // Which row is mid-resolve (per-row busy), and the shared feedback signals.
  const [busyId, setBusyId] = createSignal<number | null>(null)
  const [error, setError] = createSignal('')
  const [resolved, setResolved] = createSignal(false)

  const list = (): SyncConflict[] => conflicts.data?.conflicts ?? []

  async function resolve(id: number, winner: 'local' | 'remote'): Promise<void> {
    setBusyId(id)
    setError('')
    // Clear the previous success so the live region re-announces (and doesn't
    // linger from an earlier resolve masking a later failure).
    setResolved(false)
    try {
      await resolveConflict(id, winner)
      setResolved(true)
      // The resolved conflict is gone server-side; refetch drops it from the list.
      void queryClient.invalidateQueries({ queryKey: worklogSyncKeys.conflicts })
      // Either resolution can change local entries (keeping the remote side
      // rewrites or deletes one) — refresh the worklog grid and the header
      // day/week/month totals, which don't observe the entries cache (#620).
      void refreshEntriesAndWorktime(queryClient)
    } catch (caught) {
      setError(apiErrorMessage(caught, m.worklogsync_resolve_error()))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div class="conflict-region">
      {/* Polite success + assertive failure live regions, announced once per action. */}
      <Show when={resolved()}>
        <p role="status" class="form-status is-ok">{m.worklogsync_resolved()}</p>
      </Show>
      <Show when={error()}>
        <p role="alert" class="form-status is-error">{error()}</p>
      </Show>

      <Show
        when={list().length > 0}
        fallback={
          <Show when={!conflicts.isPending}>
            <p class="conflict-empty">{m.worklogsync_no_conflicts()}</p>
          </Show>
        }
      >
        <ul class="conflict-list">
          <For each={list()}>
            {(conflict) => <ConflictCard conflict={conflict} busy={busyId() === conflict.id} onResolve={resolve} />}
          </For>
        </ul>
      </Show>
    </div>
  )
}

function ConflictCard(props: {
  conflict: SyncConflict
  busy: boolean
  onResolve: (id: number, winner: 'local' | 'remote') => void
}): JSX.Element {
  const entry = (): SyncConflict['entry'] => props.conflict.entry
  const remote = (): SyncConflict['conflict_remote'] => props.conflict.conflict_remote

  return (
    <li class="conflict-card">
      <p class="conflict-explainer">{m.worklogsync_conflict_explainer()}</p>
      <div class="conflict-sides">
        <section class="conflict-side conflict-side-local" aria-label={m.worklogsync_conflict_local()}>
          <h3 class="conflict-side-title">{m.worklogsync_conflict_local()}</h3>
          <dl class="conflict-fields">
            <ConflictField label={m.worklogsync_conflict_ticket()} value={entry().ticket} />
            <ConflictField label={m.worklogsync_conflict_day()} value={entry().day} />
            <ConflictField label={m.worklogsync_conflict_time()} value={`${entry().start} – ${entry().end}`} />
            <ConflictField label={m.worklogsync_conflict_duration()} value={entry().duration} />
            <ConflictField label={m.worklogsync_conflict_description()} value={entry().description} />
          </dl>
        </section>

        <section class="conflict-side conflict-side-remote" aria-label={m.worklogsync_conflict_remote()}>
          <h3 class="conflict-side-title">{m.worklogsync_conflict_remote()}</h3>
          <Show
            when={remote()}
            fallback={<p class="conflict-deleted">{m.worklogsync_remote_deleted()}</p>}
          >
            {(present) => (
              <dl class="conflict-fields">
                <ConflictField label={m.worklogsync_conflict_comment()} value={present().comment ?? ''} />
                <ConflictField label={m.worklogsync_conflict_started()} value={present().started ?? ''} />
                <ConflictField label={m.worklogsync_conflict_duration()} value={toMinutes(present().timeSpentSeconds)} />
              </dl>
            )}
          </Show>
        </section>
      </div>

      <div class="conflict-actions">
        <button
          type="button"
          class="primary-button"
          disabled={props.busy}
          aria-label={`${m.worklogsync_resolve_local()}: ${entry().ticket}`}
          onClick={() => props.onResolve(props.conflict.id, 'local')}
        >
          {m.worklogsync_resolve_local()}
        </button>
        <button
          type="button"
          class="ghost-button"
          disabled={props.busy}
          aria-label={`${m.worklogsync_resolve_remote()}: ${entry().ticket}`}
          onClick={() => props.onResolve(props.conflict.id, 'remote')}
        >
          {m.worklogsync_resolve_remote()}
        </button>
      </div>
    </li>
  )
}

function ConflictField(props: { label: string; value: string | number }): JSX.Element {
  return (
    <div class="conflict-field">
      <dt>{props.label}</dt>
      <dd>{props.value}</dd>
    </div>
  )
}
