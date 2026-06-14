import { useQuery } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Show } from 'solid-js'

import {
  activitiesQuery,
  customersQuery,
  groupQuery,
  type GroupRow,
  hasInterpretationCriteria,
  type InterpretationFilters,
  type InterpretationGroup,
  lastEntriesQuery,
  projectsQuery,
  teamsQuery,
  timeSeriesQuery,
  usersQuery,
} from '../api/queries'
import { EffortChart, type EffortRow } from '../components/EffortChart'
import { OptionSelect } from '../components/OptionSelect'
import { QueryBoundary } from '../components/QueryBoundary'
import { appConfig } from '../config'
import { isoDate } from '../lib/format'
import { m } from '../paraglide/messages.js'

function defaultFilters(userId: number): InterpretationFilters {
  const now = new Date()

  return {
    datestart: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)),
    dateend: isoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)),
    customer: 0,
    project: 0,
    team: 0,
    user: userId,
    activity: 0,
    ticket: '',
    description: '',
  }
}

const GROUPS: { group: InterpretationGroup; title: () => string }[] = [
  { group: 'customer', title: () => m.auswertung_by_customer() },
  { group: 'project', title: () => m.auswertung_by_project() },
  { group: 'ticket', title: () => m.auswertung_by_ticket() },
  { group: 'activity', title: () => m.auswertung_by_activity() },
  { group: 'user', title: () => m.auswertung_by_user() },
]

function toEffortRows(rows: GroupRow[] | undefined): EffortRow[] {
  return (rows ?? []).map((row) => ({
    label: row.name,
    minutes: Math.round(row.hours * 60),
    quota: row.quota,
  }))
}

/** One grouped-effort chart, driven by the applied filters. The chart card and
 *  heading stay visible across loading/error so the layout doesn't jump. */
function GroupChart(props: { group: InterpretationGroup; title: string; filters: InterpretationFilters }) {
  const query = useQuery(() => groupQuery(props.group, props.filters))
  const rows = createMemo(() => toEffortRows(query.data))

  return (
    <QueryBoundary
      query={query}
      error={<section class="effort-chart"><h3>{props.title}</h3><p role="alert">{m.app_load_error()}</p></section>}
      loading={<section class="effort-chart"><h3>{props.title}</h3><p class="effort-empty">{m.app_loading()}</p></section>}
    >
      <EffortChart title={props.title} rows={rows()} />
    </QueryBoundary>
  )
}

export default function Auswertung() {
  const config = appConfig()
  const [filters, setFilters] = createSignal(defaultFilters(config.userId))
  const [applied, setApplied] = createSignal(defaultFilters(config.userId))

  const customers = useQuery(customersQuery)
  const projects = useQuery(projectsQuery)
  const teams = useQuery(teamsQuery)
  const users = useQuery(usersQuery)
  const activities = useQuery(activitiesQuery)

  const timeSeries = useQuery(() => timeSeriesQuery(applied()))
  const entries = useQuery(() => lastEntriesQuery(applied()))

  const set = <K extends keyof InterpretationFilters>(key: K, value: InterpretationFilters[K]) =>
    setFilters((current) => ({ ...current, [key]: value }))

  const timeRows = createMemo<EffortRow[]>(() =>
    (timeSeries.data ?? []).map((row) => ({
      label: row.day,
      minutes: Math.round(row.hours * 60),
      quota: row.quota,
    })),
  )

  return (
    <section class="auswertung">
      <h2 class="visually-hidden">{m.auswertung_title()}</h2>

      <form
        class="filter-bar"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied({ ...filters() })
        }}
      >
        <div class="field-row">
          <label class="field">
            <span>{m.auswertung_date_start()}</span>
            <input type="date" value={filters().datestart} onInput={(e) => set('datestart', e.currentTarget.value)} />
          </label>
          <label class="field">
            <span>{m.auswertung_date_end()}</span>
            <input type="date" value={filters().dateend} onInput={(e) => set('dateend', e.currentTarget.value)} />
          </label>
        </div>

        <div class="filter-grid">
          <OptionSelect label={m.auswertung_customer()} value={filters().customer} onInput={(v) => set('customer', v)} options={customers.data} allLabel={m.auswertung_all()} />
          <OptionSelect label={m.auswertung_project()} value={filters().project} onInput={(v) => set('project', v)} options={projects.data} allLabel={m.auswertung_all()} />
          <OptionSelect label={m.auswertung_team()} value={filters().team} onInput={(v) => set('team', v)} options={teams.data} allLabel={m.auswertung_all()} />
          <OptionSelect label={m.auswertung_user()} value={filters().user} onInput={(v) => set('user', v)} options={users.data} allLabel={m.auswertung_all()} />
          <OptionSelect label={m.auswertung_activity()} value={filters().activity} onInput={(v) => set('activity', v)} options={activities.data} allLabel={m.auswertung_all()} />
          <label class="field">
            <span>{m.auswertung_ticket()}</span>
            <input type="text" value={filters().ticket} onInput={(e) => set('ticket', e.currentTarget.value)} />
          </label>
          <label class="field">
            <span>{m.auswertung_description()}</span>
            <input type="text" value={filters().description} onInput={(e) => set('description', e.currentTarget.value)} />
          </label>
        </div>

        <div class="form-actions">
          <button type="submit" class="primary-button">{m.auswertung_refresh()}</button>
          <button
            type="button"
            class="action-button"
            onClick={() => {
              const reset = defaultFilters(config.userId)
              setFilters(reset)
              setApplied(reset)
            }}
          >
            {m.auswertung_reset()}
          </button>
        </div>
      </form>

      <Show
        when={hasInterpretationCriteria(applied())}
        fallback={<p class="effort-empty">{m.auswertung_pick_filter()}</p>}
      >
        <div class="effort-charts">
          <For each={GROUPS}>
            {(entry) => <GroupChart group={entry.group} title={entry.title()} filters={applied()} />}
          </For>
          <QueryBoundary
            query={timeSeries}
            error={<section class="effort-chart"><h3>{m.auswertung_by_day()}</h3><p role="alert">{m.app_load_error()}</p></section>}
            loading={<section class="effort-chart"><h3>{m.auswertung_by_day()}</h3><p class="effort-empty">{m.app_loading()}</p></section>}
          >
            <EffortChart title={m.auswertung_by_day()} rows={timeRows()} />
          </QueryBoundary>
        </div>

        <section class="effort-chart">
          <h3>{m.auswertung_last_entries()}</h3>
          <QueryBoundary query={entries}>
            <div class="table-scroll">
              <table class="data-table">
                <thead>
                  <tr>
                    <th scope="col">{m.auswertung_date()}</th>
                    <th scope="col">{m.auswertung_ticket()}</th>
                    <th scope="col">{m.auswertung_description()}</th>
                    <th scope="col" class="numeric">{m.auswertung_hours()}</th>
                  </tr>
                </thead>
                <tbody>
                  <For each={entries.data}>
                    {(record) => (
                      <tr>
                        <td>{record.entry.date}</td>
                        <td>{record.entry.ticket}</td>
                        <td>{record.entry.description}</td>
                        <td class="numeric">{record.entry.duration}</td>
                      </tr>
                    )}
                  </For>
                </tbody>
              </table>
            </div>
          </QueryBoundary>
        </section>
      </Show>
    </section>
  )
}
