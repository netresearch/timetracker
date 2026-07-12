import { createMemo, createSignal, For, Show } from 'solid-js'

import { formatMinutes } from '../lib/format'
import { m } from '../paraglide/messages.js'

type SortKey = 'label' | 'hours' | 'share'

export interface EffortRow {
  label: string
  minutes: number
  quota: string
  /** Optional expected (Soll) minutes. When set, the bar gains a contract
   *  boundary: a Soll marker, an over/under colour, and a ghost bar showing the
   *  shortfall. Only the "Effort by day" chart provides it. */
  target?: number
  /** ADR-025 §7: agent (machine) minutes for the row. When any row carries it,
   *  the table gains a separate "Agent hours" column — machine time is shown
   *  beside human labour, never summed into `minutes`. */
  agentMinutes?: number
}

/**
 * An accessible "bar chart": a real data table with a proportional bar drawn
 * inside each row. No charting library — the table is the source of truth for
 * screen readers, the bar is decorative (aria-hidden), and colours come from
 * the design tokens so it themes with light/dark automatically.
 *
 * When a row carries a `target` (the "Effort by day" chart), the bar also shows
 * the contract Soll: coloured over/under, a Soll marker tick, a ghost bar for
 * the shortfall, and the Soll printed next to the worked time (the non-colour,
 * screen-reader-visible cue — WCAG 1.4.1).
 */
export function EffortChart(props: { title: string; rows: EffortRow[] }) {
  // Memoize so the O(N) reduction runs once per rows change, not once per row
  // on every reactive read inside the <For> below. Bars scale to the larger of
  // worked-or-target so a Soll marker beyond the worked time still fits.
  const max = createMemo(() => Math.max(1, ...props.rows.map((row) => Math.max(row.minutes, row.target ?? 0))))
  const pct = (minutes: number): string => `${(minutes / max()) * 100}%`

  // ADR-025 §7: only surface the agent column when agent time actually exists,
  // so the common (human-only) report stays a three-column table.
  const hasAgent = createMemo(() => props.rows.some((row) => (row.agentMinutes ?? 0) > 0))

  // Optional column sort. Default (null) keeps the server order — value-desc for
  // the grouped charts, chronological for "by day". Clicking a header cycles
  // none → asc → desc → none. Share is proportional to minutes, so the hours and
  // share columns order identically.
  const [sort, setSort] = createSignal<{ key: SortKey; dir: 'asc' | 'desc' } | null>(null)
  const toggleSort = (key: SortKey): void => {
    setSort((current) => (current?.key !== key ? { key, dir: 'asc' } : current.dir === 'asc' ? { key, dir: 'desc' } : null))
  }
  const ariaSort = (key: SortKey): 'ascending' | 'descending' | 'none' => {
    const current = sort()

    return current?.key === key ? (current.dir === 'asc' ? 'ascending' : 'descending') : 'none'
  }
  const sortGlyph = (key: SortKey): string => {
    const current = sort()

    return current?.key === key ? (current.dir === 'asc' ? '▲' : '▼') : '⇅'
  }
  const sortedRows = createMemo<EffortRow[]>(() => {
    const current = sort()
    if (!current) {
      return props.rows
    }
    const factor = current.dir === 'asc' ? 1 : -1

    return [...props.rows].sort((a, b) =>
      current.key === 'label'
        ? factor * a.label.localeCompare(b.label, undefined, { numeric: true })
        : factor * (a.minutes - b.minutes),
    )
  })

  return (
    <section class="effort-chart">
      <h2>{props.title}</h2>
      <Show
        when={props.rows.length > 0}
        fallback={<p class="effort-empty">{m.auswertung_no_data()}</p>}
      >
        <div class="table-scroll is-scrollable">
        <table class="effort-table">
          <caption class="visually-hidden">{props.title}</caption>
          <thead>
            <tr>
              <th scope="col" aria-sort={ariaSort('label')}>
                <button type="button" class="th-sort" onClick={() => toggleSort('label')}>
                  <span>{m.auswertung_label()}</span>
                  <span class="th-sort-glyph" aria-hidden="true">{sortGlyph('label')}</span>
                </button>
              </th>
              <th scope="col" class="numeric" aria-sort={ariaSort('hours')}>
                <button type="button" class="th-sort" onClick={() => toggleSort('hours')}>
                  <span>{m.auswertung_hours()}</span>
                  <span class="th-sort-glyph" aria-hidden="true">{sortGlyph('hours')}</span>
                </button>
              </th>
              <Show when={hasAgent()}>
                <th scope="col" class="numeric">{m.auswertung_agent_hours()}</th>
              </Show>
              <th scope="col" class="numeric" aria-sort={ariaSort('share')}>
                <button type="button" class="th-sort" onClick={() => toggleSort('share')}>
                  <span>{m.auswertung_share()}</span>
                  <span class="th-sort-glyph" aria-hidden="true">{sortGlyph('share')}</span>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <For each={sortedRows()}>
              {(row) => {
                // A 0 Soll (weekend / public holiday / contract gap) is "no
                // boundary for this day": skip the over/under colour, the marker
                // and the printed Soll so a worked weekend reads as a neutral
                // bar, not a misleading green "over target 0:00".
                const hasTarget = (): boolean => row.target != null && row.target > 0
                const under = (): boolean => hasTarget() && row.minutes < (row.target ?? 0)

                return (
                  <tr>
                    <th scope="row" class="effort-bar-cell">
                      <Show when={under()}>
                        <span class="effort-bar-ghost" aria-hidden="true" style={{ width: pct(row.target ?? 0) }} />
                      </Show>
                      <span
                        class="effort-bar"
                        classList={{ 'is-over': hasTarget() && row.minutes >= (row.target ?? 0), 'is-under': under() }}
                        aria-hidden="true"
                        style={{ width: pct(row.minutes) }}
                      />
                      <Show when={hasTarget()}>
                        <span class="effort-soll-marker" aria-hidden="true" style={{ left: pct(row.target ?? 0) }} />
                      </Show>
                      <span class="effort-bar-label">{row.label}</span>
                    </th>
                    <td class="numeric">
                      {formatMinutes(row.minutes)}
                      <Show when={hasTarget()}>
                        <small class="effort-target">{m.auswertung_of_target({ target: formatMinutes(row.target ?? 0) })}</small>
                      </Show>
                    </td>
                    <Show when={hasAgent()}>
                      <td class="numeric">{(row.agentMinutes ?? 0) > 0 ? formatMinutes(row.agentMinutes ?? 0) : '—'}</td>
                    </Show>
                    <td class="numeric">{row.quota}</td>
                  </tr>
                )
              }}
            </For>
          </tbody>
        </table>
        </div>
      </Show>
    </section>
  )
}
