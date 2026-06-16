import { useQuery } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Show } from 'solid-js'

import { activitiesQuery, projectsQuery, trackingCustomersQuery, trackingEntriesQuery, type NamedOption, type TrackingEntry } from '../api/queries'
import { gridNav } from '../lib/gridNavigation'
import { m } from '../paraglide/messages.js'

// Register the directive with the JSX namespace (Solid tree-shakes unused imports).
void gridNav

const DAYS_OPTIONS = [1, 3, 7, 35] as const
const DEFAULT_DAYS = 3

// Server-computed EntryClass → row modifier, mirroring the ExtJS row borders.
// (PLAIN=1 is unstyled; DEFAULT=0 too.)
const CLASS_ROW: Record<number, string> = {
  2: 'is-daybreak',
  4: 'is-pause',
  8: 'is-overlap',
}

/**
 * The SolidJS work-log grid (/ui/tracking) — runs alongside the legacy ExtJS
 * grid until users accept it. This first slice is read-only: it renders the
 * recent entries with server-derived duration + row styling; inline editing and
 * the save path land in a follow-up.
 */
export default function Tracking() {
  const [days, setDays] = createSignal<number>(DEFAULT_DAYS)
  const entries = useQuery(() => trackingEntriesQuery(days()))
  const customers = useQuery(trackingCustomersQuery)
  const projects = useQuery(projectsQuery)
  const activities = useQuery(activitiesQuery)

  const rows = createMemo<TrackingEntry[]>(() => entries.data ?? [])

  const nameOf = (list: NamedOption[] | undefined, id: number | null): string => {
    if (id === null || id <= 0) {
      return ''
    }

    return list?.find((option) => option.id === id)?.label ?? String(id)
  }

  let daysSelectEl: HTMLSelectElement | undefined

  return (
    <section class="tracking">
      <h2 class="visually-hidden">{m.tracking_title()}</h2>

      <div class="tracking-toolbar">
        <label class="tracking-days">
          <span>{m.tracking_days_label()}</span>
          <select
            ref={(el) => { daysSelectEl = el }}
            value={String(days())}
            onChange={(event) => setDays(Number(event.currentTarget.value))}
          >
            <For each={DAYS_OPTIONS}>
              {(option) => <option value={String(option)}>{m.tracking_days_option({ count: String(option) })}</option>}
            </For>
          </select>
        </label>
      </div>

      <Show when={!entries.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table tracking-table"
            use:gridNav={{
              items: rows,
              readonly: true,
              onExit: (direction) => { if (direction === 'up') daysSelectEl?.focus() },
            }}
          >
            <thead>
              <tr>
                <th scope="col">{m.tracking_col_date()}</th>
                <th scope="col" class="numeric">{m.tracking_col_start()}</th>
                <th scope="col" class="numeric">{m.tracking_col_end()}</th>
                <th scope="col">{m.tracking_col_ticket()}</th>
                <th scope="col">{m.tracking_col_customer()}</th>
                <th scope="col">{m.tracking_col_project()}</th>
                <th scope="col">{m.tracking_col_activity()}</th>
                <th scope="col">{m.tracking_col_description()}</th>
                <th scope="col" class="numeric">{m.tracking_col_duration()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={rows()}>
                {(entry) => (
                  <tr class={`tracking-row ${CLASS_ROW[entry.class] ?? ''}`.trimEnd()}>
                    <td>{entry.date}</td>
                    <td class="numeric">{entry.start}</td>
                    <td class="numeric">{entry.end}</td>
                    <td>{entry.ticket}</td>
                    <td>{nameOf(customers.data, entry.customer)}</td>
                    <td>{nameOf(projects.data, entry.project)}</td>
                    <td>{nameOf(activities.data, entry.activity)}</td>
                    <td>{entry.description}</td>
                    <td class="numeric">{entry.duration}</td>
                  </tr>
                )}
              </For>
            </tbody>
          </table>
        </div>

        <Show when={rows().length === 0 && !entries.isLoading}>
          <p class="tracking-empty">{m.tracking_empty()}</p>
        </Show>
      </Show>
    </section>
  )
}
