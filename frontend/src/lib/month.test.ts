import { describe, expect, it } from 'vitest'

import type { ContractHoursRecord } from '../api/queries'
import { computeMonth, type ComputeMonthInput, isoWeek, summarize } from './month'
import { contractHoursPerWeekday } from './settings'

const WEEKEND = { saturday: 'Saturday', sunday: 'Sunday' }

function input(overrides: Partial<ComputeMonthInput> = {}): ComputeMonthInput {
  return {
    year: 2026,
    month: 6,
    entries: [],
    holidays: new Map(),
    hoursPerWeekday: () => 8,
    today: new Date(2026, 5, 12),
    weekendLabels: WEEKEND,
    ...overrides,
  }
}

describe('computeMonth', () => {
  it('produces one row per calendar day', () => {
    const { days } = computeMonth(input())

    expect(days).toHaveLength(30)
    expect(days[0]?.date.getDate()).toBe(1)
    expect(days[29]?.date.getDate()).toBe(30)
  })

  it('labels weekends and zeroes their expected time', () => {
    const { days } = computeMonth(input())
    const saturday = days[5]
    const sunday = days[6]

    expect(saturday?.holiday).toBe('Saturday')
    expect(sunday?.holiday).toBe('Sunday')
    expect(saturday?.expected).toBe(0)
    expect(sunday?.expected).toBe(0)
  })

  it('labels holidays from the YYYY-MM-DD keyed map', () => {
    const { days, sum } = computeMonth(
      // 2026-06-01 is a Monday.
      input({ holidays: new Map([['2026-06-01', 'Pfingstmontag']]) }),
    )

    expect(days[0]?.holiday).toBe('Pfingstmontag')
    expect(days[0]?.expected).toBe(0)
    // 21 working days era expected minus the holiday Monday.
    expect(sum.expected).toBe(21 * 8 * 60)
  })

  it('matches booked times via the two-digit-year key of /interpretation/time', () => {
    const { days } = computeMonth(
      input({ entries: [{ name: '26-06-01', hours: 7.5 }] }),
    )

    expect(days[0]?.worked).toBe(450)
    expect(days[0]?.diff).toBe(450 - 480)
  })

  it('aggregates multiple entries on the same day', () => {
    const { sum } = computeMonth(
      input({
        entries: [
          { name: '26-06-01', hours: 4 },
          { name: '26-06-01', hours: 4.25 },
        ],
      }),
    )

    expect(sum.worked).toBe(495)
  })

  it('rates worked time: success within 100%-112%, warning above half, danger otherwise', () => {
    const { days } = computeMonth(
      input({
        entries: [
          { name: '26-06-01', hours: 8 }, // exactly 100%
          { name: '26-06-02', hours: 8.95 }, // 537 min, just below the 112% cap (537.6)
          { name: '26-06-03', hours: 9 }, // > 112% -> danger
          { name: '26-06-04', hours: 4.5 }, // between 50% and 100%
          { name: '26-06-05', hours: 4 }, // exactly 50% -> danger
        ],
      }),
    )

    expect(days[0]?.status).toBe('success')
    expect(days[1]?.status).toBe('success')
    expect(days[2]?.status).toBe('danger')
    expect(days[3]?.status).toBe('warning')
    expect(days[4]?.status).toBe('danger')
  })

  it('does not rate future days or off-days', () => {
    const { days } = computeMonth(input())

    // Today (2026-06-12) is empty -> danger; the 15th is in the future -> none.
    expect(days[11]?.status).toBe('danger')
    expect(days[14]?.isFuture).toBe(true)
    expect(days[14]?.status).toBe('none')
    expect(days[5]?.status).toBe('none')
  })

  it('splits sums into full month and until-today parts', () => {
    const { sum } = computeMonth(
      input({ entries: [{ name: '26-06-01', hours: 8 }] }),
    )

    // June 2026: 22 working days, 10 of them until the 12th (Mon 1st - Fri 12th).
    expect(sum.expected).toBe(22 * 8 * 60)
    expect(sum.expectedUntilToday).toBe(10 * 8 * 60)
    expect(sum.worked).toBe(480)
    expect(sum.diff).toBe(480 - 22 * 8 * 60)
    expect(sum.diffUntilToday).toBe(480 - 10 * 8 * 60)
  })

  it('respects per-weekday expected hours', () => {
    const { days } = computeMonth(
      // 4-hour Fridays: 2026-06-05 is a Friday.
      input({ hoursPerWeekday: (weekday) => (weekday === 5 ? 4 : 8) }),
    )

    expect(days[4]?.expected).toBe(240)
    expect(days[3]?.expected).toBe(480)
  })

  it('derives expected from a contract-hours record (half-day Fridays)', () => {
    // hours_0 = Sunday … hours_6 = Saturday; 4h Fridays via hours_5.
    const contract: ContractHoursRecord = {
      hours_0: 0, hours_1: 8, hours_2: 8, hours_3: 8, hours_4: 8, hours_5: 4, hours_6: 0,
    }
    const { days } = computeMonth(input({ hoursPerWeekday: contractHoursPerWeekday(contract) }))

    // 2026-06-04 is a Thursday (8h), 2026-06-05 is a Friday (4h).
    expect(days[3]?.expected).toBe(480)
    expect(days[4]?.expected).toBe(240)
  })

  it('uses the 8h fallback when no contract record is available', () => {
    const { days } = computeMonth(input({ hoursPerWeekday: contractHoursPerWeekday(undefined) }))

    // Every working day defaults to 8h (480 min); 2026-06-01 is a Monday.
    expect(days[0]?.expected).toBe(480)
    expect(days[3]?.expected).toBe(480)
  })
})

describe('summarize', () => {
  it('sums an arbitrary day subset and matches computeMonth over the full month', () => {
    const { days, sum } = computeMonth(input({ entries: [{ name: '26-06-01', hours: 8 }, { name: '26-06-02', hours: 4 }] }))
    expect(summarize(days)).toEqual(sum)
  })

  it('only counts non-future days toward the until-today figures', () => {
    const { days } = computeMonth(input({ entries: [{ name: '26-06-01', hours: 8 }] }))
    const future = days.filter((day) => day.isFuture)
    const subset = summarize(future)
    expect(subset.expectedUntilToday).toBe(0)
    expect(subset.diffUntilToday).toBe(0)
    expect(subset.expected).toBeGreaterThan(0)
  })

  it('ignores holiday/weekend expected hours', () => {
    const { days } = computeMonth(input())
    const weekendOnly = days.filter((day) => day.holiday !== null)
    expect(summarize(weekendOnly).expected).toBe(0)
  })
})

describe('isoWeek', () => {
  it('computes ISO-8601 week numbers (Mon-start)', () => {
    expect(isoWeek(new Date(2026, 0, 1))).toBe(1) // Thu 2026-01-01 → W01
    expect(isoWeek(new Date(2026, 5, 12))).toBe(24) // Fri 2026-06-12
    expect(isoWeek(new Date(2024, 11, 30))).toBe(1) // Mon 2024-12-30 → W01 of 2025
  })
})
