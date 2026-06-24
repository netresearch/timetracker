import { createMemo, For, Show } from 'solid-js'

import { formatMinutes } from '../lib/format'
import { m } from '../paraglide/messages.js'

export interface EffortRow {
  label: string
  minutes: number
  quota: string
  /** Optional expected (Soll) minutes. When set, the bar gains a contract
   *  boundary: a Soll marker, an over/under colour, and a ghost bar showing the
   *  shortfall. Only the "Effort by day" chart provides it. */
  target?: number
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

  return (
    <section class="effort-chart">
      <h2>{props.title}</h2>
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
                    <td class="numeric">{row.quota}</td>
                  </tr>
                )
              }}
            </For>
          </tbody>
        </table>
      </Show>
    </section>
  )
}
