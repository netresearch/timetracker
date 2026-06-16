import { describe, expect, it } from 'vitest'

import { parseTime, toIsoDate } from './timeParse'

describe('parseTime', () => {
  it.each([
    ['9:30', '09:30'],
    ['09:30', '09:30'],
    ['9.30', '09:30'],
    ['9h30', '09:30'],
    ['14:05', '14:05'],
    ['930', '09:30'],
    ['0930', '09:30'],
    ['9', '09:00'],
    ['09', '09:00'],
    ['1830', '18:30'],
    ['9:30am', '09:30'],
    ['9:30a', '09:30'],
    ['9:30pm', '21:30'],
    ['930p', '21:30'],
    ['9p', '21:00'],
    ['12a', '00:00'],
    ['12p', '12:00'],
    ['  8:15 AM ', '08:15'],
  ])('parses %s → %s', (input, expected) => {
    expect(parseTime(input)).toBe(expected)
  })

  it.each(['', 'abc', '25:00', '9:75', '99'])('rejects %s', (input) => {
    expect(parseTime(input)).toBeNull()
  })
})

describe('toIsoDate', () => {
  it.each([
    ['16/06/2026', '2026-06-16'],
    ['01/01/2025', '2025-01-01'],
  ])('converts %s → %s', (input, expected) => {
    expect(toIsoDate(input)).toBe(expected)
  })

  it.each(['2026-06-16', ''])('returns empty for unrecognised %s', (input) => {
    expect(toIsoDate(input)).toBe('')
  })
})
