import { A, useSearchParams } from '@solidjs/router'
import { useQuery } from '@tanstack/solid-query'
import { createMemo, For, Match, Show, Switch } from 'solid-js'

import { holidaysQuery, monthTimesQuery } from '../api/queries'
import { ThemeToggle } from '../components/ThemeToggle'
import { appConfig } from '../config'
import { formatDay, formatMinutes, formatMonthTitle } from '../lib/format'
import { computeMonth, type DayRow } from '../lib/month'
import { hoursPerWeekday } from '../lib/settings'
import { m } from '../paraglide/messages.js'

interface MonthTarget {
  year: number
  month: number
}

function firstOfMonth(target: MonthTarget): Date {
  return new Date(target.year, target.month - 1, 1)
}

function shiftMonth(target: MonthTarget, by: number): MonthTarget {
  const date = new Date(target.year, target.month - 1 + by, 1)

  return { year: date.getFullYear(), month: date.getMonth() + 1 }
}

function monthHref(target: MonthTarget): string {
  return `/month?year=${target.year}&month=${target.month}`
}

export default function Month() {
  const config = appConfig()
  const [searchParams] = useSearchParams()

  const target = createMemo<MonthTarget>(() => {
    const now = new Date()
    const year = Number(searchParams.year ?? now.getFullYear())
    const month = Number(searchParams.month ?? now.getMonth() + 1)
    if (!Number.isInteger(year) || !Number.isInteger(month) || month < 1 || month > 12) {
      return { year: now.getFullYear(), month: now.getMonth() + 1 }
    }

    return { year, month }
  })

  // Only months in the past get a "next" link, mirroring the previous UI.
  const next = createMemo<MonthTarget | null>(() => {
    const now = new Date()
    const viewed = firstOfMonth(target())
    const currentMonth = new Date(now.getFullYear(), now.getMonth(), 1)

    return viewed.getTime() < currentMonth.getTime() ? shiftMonth(target(), 1) : null
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

  const statusClass = (status: DayRow['status']): string =>
    status === 'none' ? '' : `status-${status}`

  return (
    <section class="month-report">
      <div class="month-header">
        <h2>{m.month_title()}</h2>
        <nav class="month-nav" aria-label={m.month_title()}>
          <A class="month-nav-link" href={monthHref(shiftMonth(target(), -1))}>
            <span aria-hidden="true">&laquo;</span> {m.month_previous()}
          </A>
          <span class="month-nav-current" aria-current="date">
            {formatMonthTitle(firstOfMonth(target()), config.locale)}
          </span>
          <Show when={next()}>
            {(toMonth) => (
              <A class="month-nav-link" href={monthHref(toMonth())}>
                {m.month_next()} <span aria-hidden="true">&raquo;</span>
              </A>
            )}
          </Show>
        </nav>
        <ThemeToggle />
      </div>

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
            <div class="month-tables">
              <table class="data-table">
                <caption class="visually-hidden">
                  {m.month_title()}: {formatMonthTitle(firstOfMonth(target()), config.locale)}
                </caption>
                <thead>
                  <tr>
                    <th scope="col">{m.month_th_date()}</th>
                    <th scope="col" class="numeric">{m.month_th_worked()}</th>
                    <th scope="col" class="numeric">{m.month_th_due()}</th>
                  </tr>
                </thead>
                <tbody>
                  <For each={data().days}>
                    {(day) => (
                      <tr class={day.holiday !== null ? 'is-offday' : ''}>
                        <td>{day.holiday ?? formatDay(day.date, config.locale)}</td>
                        <td class="numeric">{formatMinutes(day.worked)}</td>
                        <td class={`numeric ${statusClass(day.status)}`}>
                          {formatMinutes(day.diff, true)}
                        </td>
                      </tr>
                    )}
                  </For>
                </tbody>
              </table>

              <table class="data-table summary-table">
                <caption>{m.month_summary()}</caption>
                <tbody>
                  <tr>
                    <th scope="row">{m.month_expected()}</th>
                    <td class="numeric">{formatMinutes(data().sum.expected)}</td>
                  </tr>
                  <tr>
                    <th scope="row">{m.month_worked()}</th>
                    <td class="numeric">{formatMinutes(data().sum.worked)}</td>
                  </tr>
                  <tr>
                    <th scope="row">{m.month_due_until_today()}</th>
                    <td
                      class={`numeric ${
                        data().sum.worked - data().sum.expectedUntilToday < 0
                          ? 'status-danger'
                          : 'status-success'
                      }`}
                    >
                      {formatMinutes(data().sum.worked - data().sum.expectedUntilToday, true)}
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">{m.month_due_until_eom()}</th>
                    <td
                      class={`numeric ${
                        data().sum.diffUntilToday < 0 ? 'status-danger' : 'status-success'
                      }`}
                    >
                      {formatMinutes(data().sum.diff, true)}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          )}
        </Match>
      </Switch>
    </section>
  )
}
