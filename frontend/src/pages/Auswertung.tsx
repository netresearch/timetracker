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
import { gridNav } from '../lib/gridNavigation'
import { m } from '../paraglide/messages.js'

function addDays(date: Date, days: number): Date {
  const next = new Date(date)
  next.setDate(next.getDate() + days)

  return next
}

/** Monday of the week containing `date` (ISO weeks, Mon-start). */
function startOfWeek(date: Date): Date {
  const monday = new Date(date)
  monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))

  return monday
}

interface RangePreset {
  key: string
  label: () => string
  /** [start, end] for the preset, evaluated against "now" on click. */
  range: () => [Date, Date]
}

const RANGE_PRESETS: RangePreset[] = [
  { key: 'today', label: () => m.range_today(), range: () => { const t = new Date(); return [t, t] } },
  { key: 'yesterday', label: () => m.range_yesterday(), range: () => { const t = addDays(new Date(), -1); return [t, t] } },
  { key: 'this_week', label: () => m.range_this_week(), range: () => { const s = startOfWeek(new Date()); return [s, addDays(s, 6)] } },
  { key: 'last_week', label: () => m.range_last_week(), range: () => { const s = addDays(startOfWeek(new Date()), -7); return [s, addDays(s, 6)] } },
  { key: 'this_month', label: () => m.range_this_month(), range: () => { const t = new Date(); return [new Date(t.getFullYear(), t.getMonth(), 1), new Date(t.getFullYear(), t.getMonth() + 1, 0)] } },
  { key: 'last_month', label: () => m.range_last_month(), range: () => { const t = new Date(); return [new Date(t.getFullYear(), t.getMonth() - 1, 1), new Date(t.getFullYear(), t.getMonth(), 0)] } },
  { key: 'this_year', label: () => m.range_this_year(), range: () => { const y = new Date().getFullYear(); return [new Date(y, 0, 1), new Date(y, 11, 31)] } },
  { key: 'last_year', label: () => m.range_last_year(), range: () => { const y = new Date().getFullYear() - 1; return [new Date(y, 0, 1), new Date(y, 11, 31)] } },
  { key: 'last_7', label: () => m.range_last_7_days(), range: () => { const t = new Date(); return [addDays(t, -6), t] } },
  { key: 'last_30', label: () => m.range_last_30_days(), range: () => { const t = new Date(); return [addDays(t, -29), t] } },
  { key: 'last_12m', label: () => m.range_last_12_months(), range: () => { const t = new Date(); return [new Date(t.getFullYear(), t.getMonth() - 11, 1), new Date(t.getFullYear(), t.getMonth() + 1, 0)] } },
]

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

  // A preset sets only the date range (other filters stay) and applies at once.
  const applyPreset = (preset: RangePreset) => {
    const [start, end] = preset.range()
    const next = { ...filters(), datestart: isoDate(start), dateend: isoDate(end) }
    setFilters(next)
    setApplied(next)
  }
  // Highlight whichever preset matches the current range (clears on manual edit).
  const activePreset = createMemo(() => {
    const current = filters()

    return RANGE_PRESETS.find((preset) => {
      const [start, end] = preset.range()

      return isoDate(start) === current.datestart && isoDate(end) === current.dateend
    })?.key
  })

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
        <div class="range-presets" role="group" aria-label={m.auswertung_range_presets()}>
          <For each={RANGE_PRESETS}>
            {(preset) => (
              <button
                type="button"
                class="range-preset"
                classList={{ 'is-active': activePreset() === preset.key }}
                aria-pressed={activePreset() === preset.key ? 'true' : 'false'}
                onClick={() => applyPreset(preset)}
              >
                {preset.label()}
              </button>
            )}
          </For>
        </div>

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
              {/* Read-only grid: arrow-navigated internally, entered/left via
                  Tab. No onExit arrow-bridge to the filter bar — its date/select
                  controls own the arrow keys, so an arrow bridge there would be a
                  one-directional trap (the search↔grid arrow chain only fits the
                  Admin page's single search field). */}
              <table class="data-table" use:gridNav={{ items: () => entries.data ?? [], readonly: true }}>
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
