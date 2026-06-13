import { createMemo, For, Show } from 'solid-js'

import { formatMinutes } from '../lib/format'
import { m } from '../paraglide/messages.js'

export interface EffortRow {
  label: string
  minutes: number
  quota: string
}

/**
 * An accessible "bar chart": a real data table with a proportional bar drawn
 * inside each row. No charting library — the table is the source of truth for
 * screen readers, the bar is decorative (aria-hidden), and colours come from
 * the design tokens so it themes with light/dark automatically.
 */
export function EffortChart(props: { title: string; rows: EffortRow[] }) {
  // Memoize so the O(N) reduction runs once per rows change, not once per row
  // on every reactive read inside the <For> below.
  const max = createMemo(() => Math.max(1, ...props.rows.map((row) => row.minutes)))

  return (
    <section class="effort-chart">
      <h3>{props.title}</h3>
      <Show
        when={props.rows.length > 0}
        fallback={<p class="effort-empty">{m.auswertung_no_data()}</p>}
      >
        <table class="effort-table">
          <caption class="visually-hidden">{props.title}</caption>
          <thead>
            <tr>
              <th scope="col">{m.auswertung_label()}</th>
              <th scope="col" class="numeric">{m.auswertung_hours()}</th>
              <th scope="col" class="numeric">{m.auswertung_share()}</th>
            </tr>
          </thead>
          <tbody>
            <For each={props.rows}>
              {(row) => (
                <tr>
                  <th scope="row" class="effort-bar-cell">
                    <span
                      class="effort-bar"
                      aria-hidden="true"
                      style={{ width: `${(row.minutes / max()) * 100}%` }}
                    />
                    <span class="effort-bar-label">{row.label}</span>
                  </th>
                  <td class="numeric">{formatMinutes(row.minutes)}</td>
                  <td class="numeric">{row.quota}</td>
                </tr>
              )}
            </For>
          </tbody>
        </table>
      </Show>
    </section>
  )
}
