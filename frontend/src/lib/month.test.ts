import { describe, expect, it } from 'vitest'

import { computeMonth, type ComputeMonthInput } from './month'

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
})
