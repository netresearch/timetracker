import { For, Show } from 'solid-js'

import type { SyncRun, SyncRunItem } from '../api/worklogSync'
import { m } from '../paraglide/messages.js'

// Status value → localized badge label. Unknown statuses fall back to the raw
// value so a future backend status still renders (paired with its is-<status>
// class), never a blank badge.
const statusLabels: Record<string, () => string> = {
  running: m.worklogsync_status_running,
  partial: m.worklogsync_status_partial,
  completed: m.worklogsync_status_completed,
  failed: m.worklogsync_status_failed,
}

// Counter key → localized row label. The backend emits many counter keys
// (created, conflicts, orphaned, …); the handful with a user-facing label are
// humanized here and everything else falls back to its raw key.
const counterLabels: Record<string, () => string> = {
  created: m.worklogsync_created,
  would_create: m.worklogsync_would_create,
}

function labelFor(map: Record<string, () => string>, key: string): string {
  return (map[key] ?? (() => key))()
}

/**
 * Shared read-only presentation of one sync run: a status badge, a counters
 * table, and — when the run carries provenance items (the single-run detail) —
 * a list of them. Reused by the self-service import and the admin sync area.
 */
export function SyncRunSummary(props: { run: SyncRun }) {
  const counters = (): [string, number][] => Object.entries(props.run.counters)
  const items = (): SyncRunItem[] => props.run.items ?? []

  return (
    <div class="sync-run-summary">
      <p class="sync-status-row">
        <span class="visually-hidden">{m.worklogsync_run_status()}</span>{' '}
        <span class={`sync-status is-${props.run.status}`}>{labelFor(statusLabels, props.run.status)}</span>
      </p>

      <Show
        when={counters().length > 0}
        fallback={<p class="sync-counters-empty" aria-hidden="true">—</p>}
      >
        <table class="sync-counters">
          <tbody>
            <For each={counters()}>
              {([key, value]) => (
                <tr>
                  <th scope="row">{labelFor(counterLabels, key)}</th>
                  <td class="numeric">{value}</td>
                </tr>
              )}
            </For>
          </tbody>
        </table>
      </Show>

      <Show when={items().length > 0}>
        <ul class="sync-items">
          <For each={items()}>
            {(item) => (
              <li class="sync-item">
                <span class={`sync-item-kind is-${item.kind}`}>{item.kind}</span>
                <Show when={item.issue_key}>
                  <span class="sync-item-issue">{item.issue_key}</span>
                </Show>
                <span class="sync-item-reason">{item.reason}</span>
              </li>
            )}
          </For>
        </ul>
      </Show>
    </div>
  )
}
