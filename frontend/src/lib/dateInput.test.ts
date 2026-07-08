import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { parseDateInput, previewDateInput } from './dateInput'
import { setDateFormat } from './dateFormat'

const TODAY = '2026-07-08'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-07-08T12:00:00'))
  setDateFormat({ mode: 'iso', pattern: 'DD.MM.YYYY' })
})

afterEach(() => {
  vi.useRealTimers()
  setDateFormat({ mode: 'iso', pattern: 'DD.MM.YYYY' })
})

describe('parseDateInput — ISO mode (default, day rightmost, read right-to-left)', () => {
  beforeEach(() => {
    setDateFormat({ mode: 'iso', pattern: 'DD.MM.YYYY' })
  })

  it.each([
    ['', TODAY], // a truly empty field means "today"
    ['   ', TODAY], // whitespace-only is still empty → today
    ['7', '2026-07-07'],
    ['708', '2026-07-08'],
    ['0708', '2026-07-08'],
    ['240505', '2024-05-05'],
    ['2026-07-08', '2026-07-08'],
    ['20260708', '2026-07-08'],
  ])('parses %j → %s', (raw, expected) => {
    expect(parseDateInput(raw, TODAY)).toBe(expected)
  })

  it.each([
    ['abc'], // a non-empty, digit-less typo is rejected (not silently → today)
    ['1332'], // day 32 does not exist
    ['9913'], // month 99 does not exist
  ])('rejects %j → null', (raw) => {
    expect(parseDateInput(raw, TODAY)).toBeNull()
  })
})

describe('parseDateInput — DE custom mode (day leftmost, read left-to-right)', () => {
  beforeEach(() => {
    setDateFormat({ mode: 'custom', pattern: 'DD.MM.YYYY' })
  })

  it.each([
    ['7', '2026-07-07'],
    ['807', '2026-07-08'],
    ['0807', '2026-07-08'],
    ['240505', '2005-05-24'], // day-first: 24th, month 05, year 2005
    ['08072026', '2026-07-08'],
    ['', TODAY],
  ])('parses %j → %s', (raw, expected) => {
    expect(parseDateInput(raw, TODAY, { mode: 'custom', pattern: 'DD.MM.YYYY' })).toBe(expected)
  })

  it('rejects an impossible day (32.01) → null', () => {
    expect(parseDateInput('3201', TODAY, { mode: 'custom', pattern: 'DD.MM.YYYY' })).toBeNull()
  })
})

describe('parseDateInput — explicit pref argument overrides the signal', () => {
  it('honors a DE pref even when the active format is ISO', () => {
    setDateFormat({ mode: 'iso', pattern: 'DD.MM.YYYY' })
    expect(parseDateInput('0807', TODAY, { mode: 'custom', pattern: 'DD.MM.YYYY' })).toBe('2026-07-08')
  })
})

describe('previewDateInput', () => {
  it('formats a valid parse in the active display format (ISO)', () => {
    setDateFormat({ mode: 'iso', pattern: 'DD.MM.YYYY' })
    expect(previewDateInput('708', TODAY)).toBe('2026-07-08')
  })

  it('formats a valid parse in the passed custom format', () => {
    expect(previewDateInput('0807', TODAY, { mode: 'custom', pattern: 'DD.MM.YYYY' })).toBe('08.07.2026')
  })

  it('returns "" for an impossible date', () => {
    expect(previewDateInput('1332', TODAY, { mode: 'iso', pattern: 'DD.MM.YYYY' })).toBe('')
  })

  it('returns todayIso formatted for empty input', () => {
    expect(previewDateInput('', TODAY, { mode: 'iso', pattern: 'DD.MM.YYYY' })).toBe('2026-07-08')
  })
})
