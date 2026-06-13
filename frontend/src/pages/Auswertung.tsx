import { useQuery } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Show } from 'solid-js'

import {
  activitiesQuery,
  customersQuery,
  groupQuery,
  type GroupRow,
  type InterpretationFilters,
  type InterpretationGroup,
  lastEntriesQuery,
  type NamedOption,
  projectsQuery,
  teamsQuery,
  timeSeriesQuery,
  usersQuery,
} from '../api/queries'
import { EffortChart, type EffortRow } from '../components/EffortChart'
import { ThemeToggle } from '../components/ThemeToggle'
import { appConfig } from '../config'
import { m } from '../paraglide/messages.js'

function isoDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

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

/** One grouped-effort chart, driven by the applied filters. */
function GroupChart(props: { group: InterpretationGroup; title: string; filters: InterpretationFilters }) {
  const query = useQuery(() => groupQuery(props.group, props.filters))

  return (
    <Show when={!query.isError} fallback={<section class="effort-chart"><h3>{props.title}</h3><p role="alert">{m.app_load_error()}</p></section>}>
      <EffortChart title={props.title} rows={toEffortRows(query.data)} />
    </Show>
  )
}

function FilterSelect(props: {
  label: string
  value: number
  onInput: (value: number) => void
  options: NamedOption[] | undefined
}) {
  return (
    <label class="field">
      <span>{props.label}</span>
      <select value={props.value} onInput={(event) => props.onInput(Number(event.currentTarget.value))}>
        <option value="0">{m.auswertung_all()}</option>
        <For each={props.options ?? []}>
          {(option) => <option value={option.id}>{option.label}</option>}
        </For>
      </select>
    </label>
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
      <div class="month-toolbar">
        <h2>{m.auswertung_title()}</h2>
        <ThemeToggle />
      </div>

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
          <FilterSelect label={m.auswertung_customer()} value={filters().customer} onInput={(v) => set('customer', v)} options={customers.data} />
          <FilterSelect label={m.auswertung_project()} value={filters().project} onInput={(v) => set('project', v)} options={projects.data} />
          <FilterSelect label={m.auswertung_team()} value={filters().team} onInput={(v) => set('team', v)} options={teams.data} />
          <FilterSelect label={m.auswertung_user()} value={filters().user} onInput={(v) => set('user', v)} options={users.data} />
          <FilterSelect label={m.auswertung_activity()} value={filters().activity} onInput={(v) => set('activity', v)} options={activities.data} />
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
        when={applied().customer > 0 || applied().project > 0 || applied().user > 0 || applied().ticket.trim() !== ''}
        fallback={<p role="status" class="effort-empty">{m.auswertung_pick_filter()}</p>}
      >
        <div class="effort-charts">
          <For each={GROUPS}>
            {(entry) => <GroupChart group={entry.group} title={entry.title()} filters={applied()} />}
          </For>
          <EffortChart title={m.auswertung_by_day()} rows={timeRows()} />
        </div>

        <section class="effort-chart">
          <h3>{m.auswertung_last_entries()}</h3>
          <Show when={!entries.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
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
                <For each={entries.data ?? []}>
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
          </Show>
        </section>
      </Show>
    </section>
  )
}
