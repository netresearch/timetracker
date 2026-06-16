import { describe, expect, it } from 'vitest'

import { parseTime, toIsoDate } from './timeParse'

describe('parseTime', () => {
  it('parses separated forms', () => {
    expect(parseTime('9:30')).toBe('09:30')
    expect(parseTime('09:30')).toBe('09:30')
    expect(parseTime('9.30')).toBe('09:30')
    expect(parseTime('9h30')).toBe('09:30')
    expect(parseTime('14:05')).toBe('14:05')
  })

  it('parses terse digit forms', () => {
    expect(parseTime('930')).toBe('09:30')
    expect(parseTime('0930')).toBe('09:30')
    expect(parseTime('9')).toBe('09:00')
    expect(parseTime('09')).toBe('09:00')
    expect(parseTime('1830')).toBe('18:30')
  })

  it('applies am/pm', () => {
    expect(parseTime('9:30am')).toBe('09:30')
    expect(parseTime('9:30a')).toBe('09:30')
    expect(parseTime('9:30pm')).toBe('21:30')
    expect(parseTime('930p')).toBe('21:30')
    expect(parseTime('9p')).toBe('21:00')
    expect(parseTime('12a')).toBe('00:00')
    expect(parseTime('12p')).toBe('12:00')
  })

  it('trims and is case-insensitive', () => {
    expect(parseTime('  8:15 AM ')).toBe('08:15')
  })

  it('rejects invalid input', () => {
    expect(parseTime('')).toBeNull()
    expect(parseTime('abc')).toBeNull()
    expect(parseTime('25:00')).toBeNull()
    expect(parseTime('9:75')).toBeNull()
    expect(parseTime('99')).toBeNull()
  })
})

describe('toIsoDate', () => {
  it('converts d/m/Y to Y-m-d', () => {
    expect(toIsoDate('16/06/2026')).toBe('2026-06-16')
    expect(toIsoDate('01/01/2025')).toBe('2025-01-01')
  })

  it('returns empty for unrecognised input', () => {
    expect(toIsoDate('2026-06-16')).toBe('')
    expect(toIsoDate('')).toBe('')
  })
})
