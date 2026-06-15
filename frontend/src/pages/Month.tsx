import { A, useSearchParams } from '@solidjs/router'
import { useQueries, useQuery } from '@tanstack/solid-query'
import { createMemo, For, Index, Match, Show, Switch } from 'solid-js'

import { holidaysQuery, type HolidayRecord, monthTimesQuery, type WorktimeRecord } from '../api/queries'
import { appConfig } from '../config'
import { formatDay, formatMinutes, formatMonthTitle, isoDate } from '../lib/format'
import { computeMonth, type DayRow, isoWeek, type MonthSummary, summarize } from '../lib/month'
import { hoursPerWeekday } from '../lib/settings'
import { m } from '../paraglide/messages.js'

interface MonthTarget {
  year: number
  /** 1-based month. */
  month: number
}

type DayCategory = 'ok' | 'over' | 'warn' | 'bad' | 'off' | 'future' | 'empty'
type Scope = 'month' | 'year' | 'selection'

interface CalendarCell {
  day: DayRow | null
  category: DayCategory
  isToday: boolean
}

interface CalendarWeek {
  week: number
  cells: CalendarCell[]
  /** ISO keys of this week's real (in-month) days. */
  dayKeys: string[]
}

function monthHref(year: number, month: number): string {
  return `/month?year=${year}&month=${month}`
}

function categorize(day: DayRow): DayCategory {
  if (day.holiday !== null) {
    return 'off'
  }

  if (day.isFuture) {
    return 'future'
  }

  if (day.worked === 0) {
    return 'bad'
  }

  if (day.diff < 0) {
    return 'warn'
  }

  return day.diff > 0 ? 'over' : 'ok'
}

function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/**
 * Full spoken description of a day cell. The visual cell conveys weekend/holiday
 * and over/under status mostly through colour and small spans; this label makes
 * the same information available to screen readers (and weekends, whose label is
 * visually hidden, are surfaced here via day.holiday).
 */
function cellAriaLabel(day: DayRow, locale: string): string {
  const date = formatDay(day.date, locale)
  if (day.holiday !== null) {
    return m.month_cell_off({ date, label: day.holiday })
  }

  if (day.isFuture) {
    return date
  }

  return m.month_cell_working({
    date,
    worked: formatMinutes(day.worked),
    expected: formatMinutes(day.expected),
    diff: formatMinutes(day.diff, true),
  })
}

/** Calendar weeks (Mon-Sun), padded with empty cells around the month, each
 *  tagged with its ISO week number and the keys of its real days. */
function buildWeeks(days: DayRow[], today: Date): CalendarWeek[] {
  const cells: CalendarCell[] = []
  const firstWeekday = days[0] ? (days[0].date.getDay() + 6) % 7 : 0
  for (let i = 0; i < firstWeekday; i++) {
    cells.push({ day: null, category: 'empty', isToday: false })
  }

  for (const day of days) {
    cells.push({ day, category: categorize(day), isToday: isSameDay(day.date, today) })
  }

  while (cells.length % 7 !== 0) {
    cells.push({ day: null, category: 'empty', isToday: false })
  }

  const weeks: CalendarWeek[] = []
  for (let i = 0; i < cells.length; i += 7) {
    const slice = cells.slice(i, i + 7)
    const realDay = slice.find((cell) => cell.day)?.day
    weeks.push({
      week: realDay ? isoWeek(realDay.date) : 0,
      cells: slice,
      dayKeys: slice.filter((cell) => cell.day).map((cell) => isoDate((cell.day as DayRow).date)),
    })
  }

  return weeks
}

/** Progress-bar fill for a day, clamped to 100%. */
function fillPercent(day: DayRow): string {
  return day.expected > 0 ? `${Math.min(100, (day.worked / day.expected) * 100)}%` : '100%'
}

/** One calendar day cell — a toggle button that adds/removes the day from the
 *  selection. Extracted so the table render doesn't nest closures too deeply. */
function CalendarDayCell(props: { cell: CalendarCell; locale: string; selected: boolean; onToggle: (key: string) => void }) {
  return (
    <Show when={props.cell.day} fallback={<td class="cell-empty" />}>
      {(day) => (
        <td class={`cell cell-${props.cell.category}`} classList={{ 'cell-today': props.cell.isToday, 'cell-selected': props.selected }}>
          <button
            type="button"
            class="cell-btn"
            aria-pressed={props.selected}
            aria-label={cellAriaLabel(day(), props.locale)}
            onClick={() => props.onToggle(isoDate(day().date))}
          >
            <span class="cell-num">{day().date.getDate()}</span>
            <Switch>
              <Match when={day().holiday !== null && day().date.getDay() !== 0 && day().date.getDay() !== 6}>
                <span class="cell-holiday">{day().holiday}</span>
              </Match>
              <Match when={props.cell.category !== 'off' && props.cell.category !== 'future'}>
                <span class="cell-time">{formatMinutes(day().worked)}</span>
                <span class="cell-delta">{formatMinutes(day().diff, true)}</span>
                <span class="cell-bar" style={{ '--fill': fillPercent(day()) }} />
              </Match>
            </Switch>
          </button>
        </td>
      )}
    </Show>
  )
}

/**
 * Resolve the `days` query param into ISO day keys. The header worktime badges
 * deep-link with symbolic tokens (`today`, `current-week`, `current-month`) that
 * are resolved here against the client's *local* clock — so they always match
 * the calendar's highlighted today, with no server-timezone skew. Any other
 * value is treated as the explicit CSV of YYYY-MM-DD keys written back on edit.
 */
export function resolveDayTokens(raw: string, nowInput: Date = new Date()): string[] {
  // Normalize to midday so the day-arithmetic below can't slip to an adjacent
  // day across a DST transition (a 23/25-hour day) or right at midnight.
  const now = new Date(nowInput.getFullYear(), nowInput.getMonth(), nowInput.getDate(), 12)
  switch (raw.trim()) {
    case 'today':
      return [isoDate(now)]
    case 'current-week': {
      const monday = new Date(now)
      monday.setDate(now.getDate() - ((now.getDay() + 6) % 7))

      return Array.from({ length: 7 }, (_, i) => {
        const day = new Date(monday)
        day.setDate(monday.getDate() + i)

        return isoDate(day)
      })
    }
    case 'current-month': {
      const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()

      return Array.from({ length: last }, (_, i) => isoDate(new Date(now.getFullYear(), now.getMonth(), i + 1)))
    }
    default:
      return raw.split(',').map((key) => key.trim()).filter(Boolean)
  }
}

export default function Month() {
  const config = appConfig()
  const [searchParams, setSearchParams] = useSearchParams()

  const target = createMemo<MonthTarget>(() => {
    const now = new Date()
    const year = Number(searchParams.year ?? now.getFullYear())
    const month = Number(searchParams.month ?? now.getMonth() + 1)
    // Bounds also guard against Number('') === 0, which Date() would map to 1900.
    if (
      !Number.isInteger(year) || year < 1970 || year > 2100
      || !Number.isInteger(month) || month < 1 || month > 12
    ) {
      return { year: now.getFullYear(), month: now.getMonth() + 1 }
    }

    return { year, month }
  })

  // --- Scope, driven by the URL so it's deep-linkable (header Today/Week badges)
  //     and survives reloads. `days` is a CSV of YYYY-MM-DD keys; `scope=year`
  //     widens the summary to the whole year. Precedence: selection > year > month.
  const selectedKeys = createMemo<ReadonlySet<string>>(() => {
    const raw = typeof searchParams.days === 'string' ? searchParams.days : ''

    return new Set(resolveDayTokens(raw))
  })
  const yearMode = createMemo(() => searchParams.scope === 'year')
  const scope = createMemo<Scope>(() => (selectedKeys().size > 0 ? 'selection' : yearMode() ? 'year' : 'month'))

  const setDays = (keys: ReadonlySet<string>) => {
    setSearchParams({ days: keys.size > 0 ? [...keys].join(',') : undefined, scope: undefined })
  }
  const toggleDay = (key: string) => {
    const next = new Set(selectedKeys())
    if (next.has(key)) {
      next.delete(key)
    } else {
      next.add(key)
    }
    setDays(next)
  }
  const toggleWeek = (dayKeys: string[]) => {
    const current = selectedKeys()
    const allSelected = dayKeys.length > 0 && dayKeys.every((key) => current.has(key))
    const next = new Set(current)
    for (const key of dayKeys) {
      if (allSelected) {
        next.delete(key)
      } else {
        next.add(key)
      }
    }
    setDays(next)
  }
  const toggleYear = () => setSearchParams({ scope: yearMode() ? undefined : 'year', days: undefined })

  const now = new Date()
  const currentTarget: MonthTarget = { year: now.getFullYear(), month: now.getMonth() + 1 }

  const monthLabels = createMemo(() => {
    const format = new Intl.DateTimeFormat(config.locale, { month: 'short' })

    return Array.from({ length: 12 }, (_, i) => format.format(new Date(2000, i, 1)))
  })

  const weekdayLabels = createMemo(() => {
    const format = new Intl.DateTimeFormat(config.locale, { weekday: 'short' })

    // 2024-01-01 is a Monday.
    return Array.from({ length: 7 }, (_, i) => format.format(new Date(2024, 0, 1 + i)))
  })

  const times = useQuery(() => monthTimesQuery(target().year, target().month, config.userId))
  const holidays = useQuery(() => holidaysQuery(target().year, target().month))

  const holidayMap = (records: HolidayRecord[]) => new Map(records.map((record) => [record.holiday.date, record.holiday.name]))
  const weekendLabels = () => ({ saturday: m.month_saturday(), sunday: m.month_sunday() })

  const report = createMemo(() => {
    const timesData = times.data
    const holidaysData = holidays.data
    if (timesData === undefined || holidaysData === undefined) {
      return null
    }

    return computeMonth({
      year: target().year,
      month: target().month,
      entries: timesData,
      holidays: holidayMap(holidaysData),
      hoursPerWeekday,
      today: new Date(),
      weekendLabels: weekendLabels(),
    })
  })

  // --- Year scope: fetch all 12 months lazily and aggregate via computeMonth.
  const yearTimes = useQueries(() => {
    const enabled = yearMode()
    const year = target().year

    return { queries: Array.from({ length: 12 }, (_, i) => ({ ...monthTimesQuery(year, i + 1, config.userId), enabled })) }
  })
  const yearHolidays = useQueries(() => {
    const enabled = yearMode()
    const year = target().year

    return { queries: Array.from({ length: 12 }, (_, i) => ({ ...holidaysQuery(year, i + 1), enabled })) }
  })
  const yearDays = createMemo<DayRow[] | null>(() => {
    if (!yearMode()) {
      return null
    }
    const days: DayRow[] = []
    for (let i = 0; i < 12; i++) {
      const t = yearTimes[i]?.data as WorktimeRecord[] | undefined
      const h = yearHolidays[i]?.data as HolidayRecord[] | undefined
      if (t === undefined || h === undefined) {
        return null
      }
      days.push(...computeMonth({
        year: target().year,
        month: i + 1,
        entries: t,
        holidays: holidayMap(h),
        hoursPerWeekday,
        today: new Date(),
        weekendLabels: weekendLabels(),
      }).days)
    }

    return days
  })

  // Days the active scope summarises over.
  const scopeDays = createMemo<DayRow[] | null>(() => {
    if (scope() === 'year') {
      return yearDays()
    }
    const data = report()
    if (data === null) {
      return null
    }

    return scope() === 'selection'
      ? data.days.filter((day) => selectedKeys().has(isoDate(day.date)))
      : data.days
  })

  const activeSummary = createMemo<MonthSummary | null>(() => {
    const days = scopeDays()

    return days === null ? null : summarize(days)
  })
  const workingDays = createMemo(() => (scopeDays() ?? []).filter((day) => day.holiday === null).length)

  const ringPercent = createMemo(() => {
    const sum = activeSummary()
    if (sum === null) {
      return 100
    }
    // Month/year measure progress against expected-until-today; an explicit
    // day/week selection measures against its own expected total.
    const base = scope() === 'selection' ? sum.expected : sum.expectedUntilToday
    if (base === 0) {
      return 100
    }

    return Math.min(100, Math.round((sum.worked / base) * 1000) / 10)
  })

  const scopeTitle = createMemo(() => {
    if (scope() === 'year') {
      return String(target().year)
    }
    if (scope() === 'selection') {
      return m.month_selected_days({ count: selectedKeys().size })
    }

    return formatMonthTitle(new Date(target().year, target().month - 1, 1), config.locale)
  })

  const monthTitle = createMemo(() =>
    formatMonthTitle(new Date(target().year, target().month - 1, 1), config.locale),
  )
  const weeks = createMemo<CalendarWeek[]>(() => {
    const data = report()

    return data === null ? [] : buildWeeks(data.days, new Date())
  })

  const isLoading = createMemo(() => (scope() === 'year' ? yearDays() === null || report() === null : report() === null))
  const isError = createMemo(() =>
    times.isError || holidays.isError
    || (scope() === 'year' && (yearTimes.some((q) => q.isError) || yearHolidays.some((q) => q.isError))),
  )

  return (
    <section class="month-report">
      <h2 class="visually-hidden">{m.month_title()}</h2>

      <nav class="month-chips" aria-label={m.month_title()}>
        <span class="year-switch">
          <A
            class="year-arrow"
            aria-label={m.month_previous_year()}
            href={monthHref(target().year - 1, target().month)}
          >
            <span aria-hidden="true">‹</span>
          </A>
          <button
            type="button"
            class="year-toggle"
            classList={{ 'is-active': yearMode() }}
            aria-pressed={yearMode()}
            aria-label={m.month_year_scope({ year: target().year })}
            onClick={toggleYear}
          >
            {target().year}
          </button>
          <Show
            when={target().year < currentTarget.year}
            fallback={<span class="year-arrow is-disabled" aria-hidden="true">›</span>}
          >
            <A
              class="year-arrow"
              aria-label={m.month_next_year()}
              href={monthHref(target().year + 1, target().month)}
            >
              <span aria-hidden="true">›</span>
            </A>
          </Show>
        </span>
        <Index each={monthLabels()}>
          {(label, index) => {
            const month = index + 1
            const isFuture = () =>
              target().year > currentTarget.year
              || (target().year === currentTarget.year && month > currentTarget.month)
            const isActive = () => month === target().month && scope() === 'month'

            return (
              <Show
                when={!isFuture()}
                fallback={<span class="month-chip is-future">{label()}</span>}
              >
                <A
                  class="month-chip"
                  classList={{ 'is-active': isActive() }}
                  aria-current={isActive() ? 'page' : undefined}
                  href={monthHref(target().year, month)}
                >
                  {label()}
                </A>
              </Show>
            )
          }}
        </Index>
        <A class="today-button month-today" href={monthHref(currentTarget.year, currentTarget.month)}>
          {m.month_today()}
        </A>
      </nav>

      <Switch>
        <Match when={isError()}>
          <p role="alert">{m.app_load_error()}</p>
          <button
            type="button"
            class="action-button"
            onClick={() => {
              times.refetch()
              holidays.refetch()
            }}
          >
            {m.app_retry()}
          </button>
        </Match>
        <Match when={isLoading()}>
          <p role="status">{m.app_loading()}</p>
        </Match>
        <Match when={activeSummary()}>
          {(sum) => (
            <div class="month-layout">
              <div class="table-scroll">
              <table class="calendar">
                <caption class="visually-hidden">
                  {m.month_calendar_label({ month: monthTitle() })}
                </caption>
                <thead>
                  <tr>
                    <th scope="col"><span class="visually-hidden">{m.month_calendar_week()}</span></th>
                    <For each={weekdayLabels()}>{(label) => <th scope="col">{label}</th>}</For>
                  </tr>
                </thead>
                <tbody>
                  <For each={weeks()}>
                    {(week) => {
                      const weekSelected = () => week.dayKeys.length > 0 && week.dayKeys.every((key) => selectedKeys().has(key))

                      return (
                        <tr classList={{ 'week-selected': weekSelected() }}>
                          <th scope="row" class="cw-cell">
                            <button
                              type="button"
                              class="cw-badge"
                              aria-pressed={weekSelected()}
                              aria-label={m.month_calendar_week_n({ week: week.week })}
                              onClick={() => toggleWeek(week.dayKeys)}
                            >
                              {week.week}
                            </button>
                          </th>
                          <For each={week.cells}>
                            {(cell) => (
                              <CalendarDayCell
                                cell={cell}
                                locale={config.locale}
                                selected={cell.day !== null && selectedKeys().has(isoDate(cell.day.date))}
                                onToggle={toggleDay}
                              />
                            )}
                          </For>
                        </tr>
                      )
                    }}
                  </For>
                </tbody>
              </table>
              </div>

              <aside class="summary-card">
                <h3>{m.month_summary()} · <span class="summary-scope">{scopeTitle()}</span></h3>
                <div class="summary-ring" style={{ '--pct': `${ringPercent()}%` }}>
                  <div>
                    <b class={(scope() === 'selection' ? sum().diff : sum().diffUntilToday) < 0 ? 'is-neg' : 'is-pos'}>
                      {formatMinutes(scope() === 'selection' ? sum().diff : sum().diffUntilToday, true)}
                    </b>
                    <span>{scope() === 'selection' ? m.month_balance() : m.month_balance_until_today()}</span>
                  </div>
                  <span class="visually-hidden">{m.month_progress({ pct: ringPercent() })}</span>
                </div>
                <table class="summary-table">
                  <tbody>
                    <tr>
                      <th scope="row">{scope() === 'month' ? m.month_expected_month() : m.month_expected()}</th>
                      <td>{formatMinutes(sum().expected)}<small> · {m.month_working_days({ count: workingDays() })}</small></td>
                    </tr>
                    <tr>
                      <th scope="row">{m.month_worked()}</th>
                      <td>{formatMinutes(sum().worked)}</td>
                    </tr>
                    <Show when={scope() === 'selection'} fallback={
                      <>
                        <tr>
                          <th scope="row">{m.month_expected_until_today()}</th>
                          <td>{formatMinutes(sum().expectedUntilToday)}</td>
                        </tr>
                        <tr>
                          <th scope="row">{m.month_balance_until_today()}</th>
                          <td class={sum().diffUntilToday < 0 ? 'is-neg' : 'is-pos'}>
                            {formatMinutes(sum().diffUntilToday, true)}
                          </td>
                        </tr>
                        <tr>
                          <th scope="row">{scope() === 'year' ? m.month_balance() : m.month_balance_eom()}</th>
                          <td class={sum().diff < 0 ? 'is-neg' : 'is-pos'}>
                            {formatMinutes(sum().diff, true)}
                          </td>
                        </tr>
                      </>
                    }>
                      <tr>
                        <th scope="row">{m.month_balance()}</th>
                        <td class={sum().diff < 0 ? 'is-neg' : 'is-pos'}>
                          {formatMinutes(sum().diff, true)}
                        </td>
                      </tr>
                    </Show>
                  </tbody>
                </table>
              </aside>
            </div>
          )}
        </Match>
      </Switch>
    </section>
  )
}
