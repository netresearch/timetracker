import { describe, expect, it } from 'vitest'

import { formatDay, formatMinutes, formatMonthTitle } from './format'

describe('formatMinutes', () => {
  it('formats positive minutes as HH:MM', () => {
    expect(formatMinutes(480)).toBe('08:00')
    expect(formatMinutes(495)).toBe('08:15')
    expect(formatMinutes(0)).toBe('00:00')
  })

  it('always signs negative values', () => {
    expect(formatMinutes(-90)).toBe('-01:30')
    expect(formatMinutes(-90, true)).toBe('-01:30')
  })

  it('signs positive values only on request', () => {
    expect(formatMinutes(90, true)).toBe('+01:30')
    expect(formatMinutes(90)).toBe('01:30')
    expect(formatMinutes(0, true)).toBe('00:00')
  })
})

describe('date formatting', () => {
  const date = new Date(2026, 5, 9)

  it('formats the month title per locale', () => {
    expect(formatMonthTitle(date, 'en')).toBe('June 2026')
    expect(formatMonthTitle(date, 'de')).toBe('Juni 2026')
  })

  it('formats day rows per locale', () => {
    expect(formatDay(date, 'en')).toBe('Jun 9, 2026')
    expect(formatDay(date, 'de')).toBe('09.06.2026')
  })
})
