// Pure month-report calculation, ported from timetracker-ui's Month.vue
// (analyze()). Kept framework-free so it is unit-testable in isolation.

export type DayStatus = 'none' | 'success' | 'warning' | 'danger'

export interface DayRow {
  date: Date
  /** Holiday or weekend label; null on regular working days. */
  holiday: string | null
  /** Booked minutes. */
  worked: number
  /** Expected minutes (0 on holidays/weekends). */
  expected: number
  /** worked - expected. */
  diff: number
  isFuture: boolean
  status: DayStatus
}

export interface MonthSummary {
  worked: number
  expected: number
  expectedUntilToday: number
  diff: number
  diffUntilToday: number
}

export interface ComputeMonthInput {
  year: number
  /** 1-based month. */
  month: number
  /** Booked times: name = 'yy-mm-dd' (two-digit year!), hours as float. */
  entries: ReadonlyArray<{ name: string; hours: number }>
  /** Holidays keyed 'YYYY-MM-DD' → display name. */
  holidays: ReadonlyMap<string, string>
  /** Expected hours per JS weekday (1 = Monday … 5 = Friday). */
  hoursPerWeekday: (weekday: number) => number
  today: Date
  weekendLabels: { saturday: string; sunday: string }
}

function pad2(value: number): string {
  return String(value).padStart(2, '0')
}

/** Key format used by /interpretation/time records: 'yy-mm-dd'. */
function shortDateKey(date: Date): string {
  return `${pad2(date.getFullYear() % 100)}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
}

/** Key format used by /getHolidays records: 'YYYY-MM-DD'. */
function isoDateKey(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
}

function isAfterDay(date: Date, reference: Date): boolean {
  const a = new Date(date.getFullYear(), date.getMonth(), date.getDate())
  const b = new Date(reference.getFullYear(), reference.getMonth(), reference.getDate())

  return a.getTime() > b.getTime()
}

// Thresholds ported verbatim: full day = 100%–112% of expected, half day and
// more = warning, anything else = danger. Holidays and future days are unrated.
function dayStatus(day: Pick<DayRow, 'holiday' | 'isFuture' | 'worked' | 'expected'>): DayStatus {
  if (day.holiday !== null || day.isFuture) {
    return 'none'
  }

  if (day.worked >= day.expected && day.worked <= day.expected * 1.12) {
    return 'success'
  }

  if (day.worked > day.expected * 0.5 && day.worked < day.expected) {
    return 'warning'
  }

  return 'danger'
}

export function computeMonth(input: ComputeMonthInput): { days: DayRow[]; sum: MonthSummary } {
  const minutesByDay = new Map<string, number>()
  const sum: MonthSummary = { worked: 0, expected: 0, expectedUntilToday: 0, diff: 0, diffUntilToday: 0 }

  for (const entry of input.entries) {
    const minutes = Math.round(entry.hours * 60)
    minutesByDay.set(entry.name, (minutesByDay.get(entry.name) ?? 0) + minutes)
    sum.worked += minutes
  }

  const days: DayRow[] = []
  const cursor = new Date(input.year, input.month - 1, 1)

  while (cursor.getMonth() === input.month - 1) {
    const weekday = cursor.getDay()
    const holiday
      = weekday === 0
        ? input.weekendLabels.sunday
        : weekday === 6
          ? input.weekendLabels.saturday
          : (input.holidays.get(isoDateKey(cursor)) ?? null)

    const expected = holiday !== null ? 0 : input.hoursPerWeekday(weekday) * 60
    const worked = minutesByDay.get(shortDateKey(cursor)) ?? 0
    const diff = worked - expected
    const isFuture = isAfterDay(cursor, input.today)

    sum.diff += diff
    if (!isFuture) {
      sum.diffUntilToday += diff
    }

    if (holiday === null) {
      sum.expected += expected
      if (!isFuture) {
        sum.expectedUntilToday += expected
      }
    }

    const day: DayRow = {
      date: new Date(cursor.getTime()),
      holiday,
      worked,
      expected,
      diff,
      isFuture,
      status: 'none',
    }
    day.status = dayStatus(day)
    days.push(day)

    cursor.setDate(cursor.getDate() + 1)
  }

  return { days, sum }
}
