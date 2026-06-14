import { A, useSearchParams } from '@solidjs/router'
import { useQuery } from '@tanstack/solid-query'
import { createMemo, For, Index, Match, Show, Switch } from 'solid-js'

import { holidaysQuery, monthTimesQuery } from '../api/queries'
import { appConfig } from '../config'
import { formatDay, formatMinutes, formatMonthTitle } from '../lib/format'
import { computeMonth, type DayRow } from '../lib/month'
import { hoursPerWeekday } from '../lib/settings'
import { m } from '../paraglide/messages.js'

interface MonthTarget {
  year: number
  /** 1-based month. */
  month: number
}

type DayCategory = 'ok' | 'over' | 'warn' | 'bad' | 'off' | 'future' | 'empty'

interface CalendarCell {
  day: DayRow | null
  category: DayCategory
  isToday: boolean
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

/** Calendar weeks (Mon-Sun), padded with empty cells around the month. */
function buildWeeks(days: DayRow[], today: Date): CalendarCell[][] {
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

  const weeks: CalendarCell[][] = []
  for (let i = 0; i < cells.length; i += 7) {
    weeks.push(cells.slice(i, i + 7))
  }

  return weeks
}

export default function Month() {
  const config = appConfig()
  const [searchParams] = useSearchParams()

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
      holidays: new Map(holidaysData.map((record) => [record.holiday.date, record.holiday.name])),
      hoursPerWeekday,
      today: new Date(),
      weekendLabels: { saturday: m.month_saturday(), sunday: m.month_sunday() },
    })
  })

  const workingDays = createMemo(() => {
    const data = report()

    return data === null ? 0 : data.days.filter((day) => day.holiday === null).length
  })

  const ringPercent = createMemo(() => {
    const data = report()
    if (data === null || data.sum.expectedUntilToday === 0) {
      return 100
    }

    return Math.min(100, Math.round((data.sum.worked / data.sum.expectedUntilToday) * 1000) / 10)
  })

  const monthTitle = createMemo(() =>
    formatMonthTitle(new Date(target().year, target().month - 1, 1), config.locale),
  )
  const weeks = createMemo(() => {
    const data = report()

    return data === null ? [] : buildWeeks(data.days, new Date())
  })

  return (
    <section class="month-report">
      <div class="month-toolbar">
        <h2 class="visually-hidden">{m.month_title()}</h2>
        <div class="month-toolbar-actions">
          <A class="today-button" href={monthHref(currentTarget.year, currentTarget.month)}>
            {m.month_today()}
          </A>
        </div>
      </div>

      <nav class="month-chips" aria-label={m.month_title()}>
        <span class="year-switch">
          <A
            class="year-arrow"
            aria-label={m.month_previous_year()}
            href={monthHref(target().year - 1, target().month)}
          >
            <span aria-hidden="true">‹</span>
          </A>
          <b>{target().year}</b>
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
            const isActive = () => month === target().month

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
      </nav>

      <Switch>
        <Match when={times.isError || holidays.isError}>
          <p role="alert">{m.app_load_error()}</p>
          <button
            type="button"
            class="action-button"
            onClick={() => {
              void times.refetch()
              void holidays.refetch()
            }}
          >
            {m.app_retry()}
          </button>
        </Match>
        <Match when={report() === null}>
          <p role="status">{m.app_loading()}</p>
        </Match>
        <Match when={report()}>
          {(data) => (
            <div class="month-layout">
              <div class="table-scroll">
              <table class="calendar">
                <caption class="visually-hidden">
                  {m.month_calendar_label({ month: monthTitle() })}
                </caption>
                <thead>
                  <tr>
                    <For each={weekdayLabels()}>{(label) => <th scope="col">{label}</th>}</For>
                  </tr>
                </thead>
                <tbody>
                  <For each={weeks()}>
                    {(week) => (
                      <tr>
                        <For each={week}>
                          {(cell) => (
                            <Show when={cell.day} fallback={<td class="cell-empty" />}>
                              {(day) => (
                                <td
                                  class={`cell cell-${cell.category}`}
                                  classList={{ 'cell-today': cell.isToday }}
                                  aria-label={cellAriaLabel(day(), config.locale)}
                                >
                                  <span class="cell-num">{day().date.getDate()}</span>
                                  <Switch>
                                    <Match when={day().holiday !== null && day().date.getDay() !== 0 && day().date.getDay() !== 6}>
                                      <span class="cell-holiday">{day().holiday}</span>
                                    </Match>
                                    <Match when={cell.category !== 'off' && cell.category !== 'future'}>
                                      <span class="cell-time">{formatMinutes(day().worked)}</span>
                                      <span class="cell-delta">{formatMinutes(day().diff, true)}</span>
                                      <span
                                        class="cell-bar"
                                        style={{
                                          '--fill': day().expected > 0
                                            ? `${Math.min(100, (day().worked / day().expected) * 100)}%`
                                            : '100%',
                                        }}
                                      />
                                    </Match>
                                  </Switch>
                                </td>
                              )}
                            </Show>
                          )}
                        </For>
                      </tr>
                    )}
                  </For>
                </tbody>
              </table>
              </div>

              <aside class="summary-card">
                <h3>{m.month_summary()}</h3>
                <div class="summary-ring" style={{ '--pct': `${ringPercent()}%` }}>
                  <div>
                    <b class={data().sum.diffUntilToday < 0 ? 'is-neg' : 'is-pos'}>
                      {formatMinutes(data().sum.diffUntilToday, true)}
                    </b>
                    <span>{m.month_balance_until_today()}</span>
                  </div>
                  <span class="visually-hidden">{m.month_progress({ pct: ringPercent() })}</span>
                </div>
                <table class="summary-table">
                  <tbody>
                    <tr>
                      <th scope="row">{m.month_expected_month()}</th>
                      <td>{formatMinutes(data().sum.expected)}<small> · {m.month_working_days({ count: workingDays() })}</small></td>
                    </tr>
                    <tr>
                      <th scope="row">{m.month_worked()}</th>
                      <td>{formatMinutes(data().sum.worked)}</td>
                    </tr>
                    <tr>
                      <th scope="row">{m.month_expected_until_today()}</th>
                      <td>{formatMinutes(data().sum.expectedUntilToday)}</td>
                    </tr>
                    <tr>
                      <th scope="row">{m.month_balance_until_today()}</th>
                      <td class={data().sum.diffUntilToday < 0 ? 'is-neg' : 'is-pos'}>
                        {formatMinutes(data().sum.diffUntilToday, true)}
                      </td>
                    </tr>
                    <tr>
                      <th scope="row">{m.month_balance_eom()}</th>
                      <td class={data().sum.diff < 0 ? 'is-neg' : 'is-pos'}>
                        {formatMinutes(data().sum.diff, true)}
                      </td>
                    </tr>
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
