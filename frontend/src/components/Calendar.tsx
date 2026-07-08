import { createEffect, createMemo, createSignal, For, on } from 'solid-js'

import { appConfig } from '../config'
import { formatDay, formatMonthTitle, pad2 } from '../lib/format'
import { m } from '../paraglide/messages.js'

interface CalendarProps {
  /** ISO yyyy-mm-dd of the selected day, or '' for none. */
  value: string
  /** Called with the clicked day's ISO yyyy-mm-dd. */
  onSelect: (iso: string) => void
  /** Today's ISO yyyy-mm-dd — the caller owns "now" (frozen clock in tests). */
  todayIso: string
}

interface DayCell {
  iso: string
  /** Day-of-month number shown in the button. */
  day: number
  /** Whether the day belongs to the month currently on display. */
  inMonth: boolean
}

const ISO_SHAPE = /^\d{4}-\d{2}-\d{2}$/

// All arithmetic is done in UTC (Date.UTC / getUTC*) so a date-only value can
// never drift by a day across a timezone/DST boundary — mirroring isoDate in
// lib/format.ts and formatWith in lib/dateFormat.ts. DISPLAY formatting (month
// title, weekday names, per-day labels) uses local Dates, which Intl renders in
// the local zone without any month/day edge case.
function toParts(iso: string): { y: number; m0: number; d: number } {
  const [y, m, d] = iso.split('-').map(Number)

  return { y: y!, m0: m! - 1, d: d! }
}

function fmtUtc(date: Date): string {
  return `${date.getUTCFullYear()}-${pad2(date.getUTCMonth() + 1)}-${pad2(date.getUTCDate())}`
}

function ymd(y: number, m0: number, d: number): string {
  return fmtUtc(new Date(Date.UTC(y, m0, d)))
}

function addDays(iso: string, n: number): string {
  const { y, m0, d } = toParts(iso)

  return fmtUtc(new Date(Date.UTC(y, m0, d + n)))
}

function daysInMonth(y: number, m0: number): number {
  return new Date(Date.UTC(y, m0 + 1, 0)).getUTCDate()
}

/** Same day-of-month n months away, clamped to the target month's length. */
function addMonths(iso: string, n: number): string {
  const { y, m0, d } = toParts(iso)
  const total = m0 + n
  const ty = y + Math.floor(total / 12)
  const tm = ((total % 12) + 12) % 12

  return ymd(ty, tm, Math.min(d, daysInMonth(ty, tm)))
}

/** Weekday index with Monday = 0 … Sunday = 6. */
function weekdayMon(iso: string): number {
  const { y, m0, d } = toParts(iso)

  return (new Date(Date.UTC(y, m0, d)).getUTCDay() + 6) % 7
}

/**
 * A small, self-contained month grid for picking a single day. Week starts
 * Monday; weekday and month names come from the app locale via Intl (never
 * hardcoded). Full keyboard support with a roving tabindex: Left/Right/Up/Down
 * step by day (crossing month boundaries), Home/End jump to the start/end of the
 * focused week, PageUp/PageDown change month, and Enter/Space (native button)
 * selects. The grid follows the WAI-ARIA grid pattern (role grid/row/gridcell,
 * aria-current on today, aria-selected on the chosen day).
 */
export function Calendar(props: CalendarProps) {
  let gridRef: HTMLDivElement | undefined
  // eslint-disable-next-line solid/reactivity -- one-time initial seed; the effect below re-syncs to props.value
  const seed = ISO_SHAPE.test(props.value) ? props.value : props.todayIso
  // The day that owns the roving tab stop. Its month is what the grid displays.
  const [focusedIso, setFocusedIso] = createSignal(seed)

  // Follow an external value change (e.g. the field's value edited elsewhere) —
  // but never yank the roving stop while the user is navigating inside the grid.
  // `on` tracks props.value ONLY, so our own focusedIso moves (month nav, arrows)
  // don't re-trigger this and pin the view back to props.value.
  createEffect(on(() => props.value, (next) => {
    if (ISO_SHAPE.test(next) && next !== focusedIso() && gridRef?.contains(document.activeElement) !== true) {
      setFocusedIso(next)
    }
  }, { defer: true }))

  const view = createMemo(() => toParts(focusedIso()))
  const locale = (): string => appConfig().locale

  // Monday-first weekday names, derived from the locale. 2024-01-01 is a Monday;
  // local Dates keep Intl in the local zone (no UTC month/day edge case).
  const weekdays = createMemo(() => {
    const short = new Intl.DateTimeFormat(locale(), { weekday: 'short' })
    const long = new Intl.DateTimeFormat(locale(), { weekday: 'long' })

    return Array.from({ length: 7 }, (_, i) => {
      const date = new Date(2024, 0, 1 + i)

      return { short: short.format(date), long: long.format(date) }
    })
  })

  const monthTitle = createMemo(() => formatMonthTitle(new Date(view().y, view().m0, 1), locale()))

  // Full weeks (Mon–Sun) spanning the display month, padded with the adjacent
  // months' days so every cell is a navigable button and arrow keys cross the
  // month boundary seamlessly.
  const weeks = createMemo<DayCell[][]>(() => {
    const { y, m0 } = view()
    const firstIso = ymd(y, m0, 1)
    const lastIso = ymd(y, m0, daysInMonth(y, m0))
    const start = addDays(firstIso, -weekdayMon(firstIso))
    const end = addDays(lastIso, 6 - weekdayMon(lastIso))

    const out: DayCell[][] = []
    let cur = start
    while (cur <= end) {
      const week: DayCell[] = []
      for (let i = 0; i < 7; i += 1) {
        week.push({ iso: cur, day: toParts(cur).d, inMonth: cur >= firstIso && cur <= lastIso })
        cur = addDays(cur, 1)
      }
      out.push(week)
    }

    return out
  })

  // Move the roving stop AND DOM focus to a day (used by arrow keys / Today). The
  // target button may be in a newly-rendered week, so focus on the next microtask
  // once Solid has flushed the grid update.
  const moveFocus = (iso: string): void => {
    setFocusedIso(iso)
    queueMicrotask(() => gridRef?.querySelector<HTMLButtonElement>(`button[data-iso="${iso}"]`)?.focus())
  }

  const onGridKeyDown = (event: KeyboardEvent): void => {
    const button = (event.target as HTMLElement).closest<HTMLButtonElement>('button[data-iso]')
    if (button === null) {
      return
    }
    const iso = button.dataset.iso!
    let next: string
    switch (event.key) {
      case 'ArrowLeft':
        next = addDays(iso, -1)
        break
      case 'ArrowRight':
        next = addDays(iso, 1)
        break
      case 'ArrowUp':
        next = addDays(iso, -7)
        break
      case 'ArrowDown':
        next = addDays(iso, 7)
        break
      case 'Home':
        next = addDays(iso, -weekdayMon(iso))
        break
      case 'End':
        next = addDays(iso, 6 - weekdayMon(iso))
        break
      case 'PageUp':
        next = addMonths(iso, -1)
        break
      case 'PageDown':
        next = addMonths(iso, 1)
        break
      default:
        return
    }
    event.preventDefault()
    moveFocus(next)
  }

  const select = (iso: string): void => {
    setFocusedIso(iso)
    props.onSelect(iso)
  }

  return (
    <div class="calendar">
      <div class="calendar-header">
        <button
          type="button"
          class="calendar-nav"
          aria-label={m.calendar_prev_month()}
          onClick={() => setFocusedIso(addMonths(focusedIso(), -1))}
        >
          ‹
        </button>
        <div class="calendar-title" aria-live="polite">{monthTitle()}</div>
        <button
          type="button"
          class="calendar-nav"
          aria-label={m.calendar_next_month()}
          onClick={() => setFocusedIso(addMonths(focusedIso(), 1))}
        >
          ›
        </button>
      </div>

      <div
        ref={(element) => {
          gridRef = element
        }}
        class="calendar-grid"
        role="grid"
        aria-label={monthTitle()}
        onKeyDown={onGridKeyDown}
      >
        <div class="calendar-weekrow calendar-weekdays" role="row">
          <For each={weekdays()}>
            {(weekday) => (
              <div class="calendar-weekday" role="columnheader" aria-label={weekday.long}>
                {weekday.short}
              </div>
            )}
          </For>
        </div>
        <For each={weeks()}>
          {(week) => (
            <div class="calendar-weekrow" role="row">
              <For each={week}>
                {(cell) => (
                  <div class="calendar-cell" role="gridcell" aria-selected={cell.iso === props.value ? 'true' : 'false'}>
                    <button
                      type="button"
                      class="calendar-day"
                      data-iso={cell.iso}
                      data-outside={cell.inMonth ? undefined : ''}
                      data-today={cell.iso === props.todayIso ? '' : undefined}
                      data-selected={cell.iso === props.value ? '' : undefined}
                      data-future={cell.iso > props.todayIso ? '' : undefined}
                      tabindex={cell.iso === focusedIso() ? 0 : -1}
                      aria-current={cell.iso === props.todayIso ? 'date' : undefined}
                      aria-label={formatDay(new Date(toParts(cell.iso).y, toParts(cell.iso).m0, cell.day), locale())}
                      onClick={() => select(cell.iso)}
                    >
                      {cell.day}
                    </button>
                  </div>
                )}
              </For>
            </div>
          )}
        </For>
      </div>

      <button type="button" class="calendar-today-link" onClick={() => moveFocus(props.todayIso)}>
        {m.calendar_today()}
      </button>
    </div>
  )
}
