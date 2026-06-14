import { describe, expect, it } from 'vitest'

import { formatDays, formatDuration } from './header'

describe('formatDuration', () => {
  it('formats minutes as H:MM like the ExtJS header', () => {
    expect(formatDuration(0)).toBe('0:00')
    expect(formatDuration(65)).toBe('1:05')
    expect(formatDuration(480)).toBe('8:00')
  })

  it('appends person-days above one day when requested', () => {
    expect(formatDuration(480, true)).toBe('8:00')
    expect(formatDuration(960, true)).toBe('16:00 (2 PT)')
    expect(formatDuration(720, true)).toBe('12:00 (1.5 PT)')
    expect(formatDuration(960)).toBe('16:00')
  })
})

describe('formatDays', () => {
  it('formats minutes as person-days only for the Month badge', () => {
    expect(formatDays(0)).toBe('0 PT')
    expect(formatDays(480)).toBe('1 PT')
    expect(formatDays(720)).toBe('1.5 PT')
    expect(formatDays(8880)).toBe('18.5 PT')
  })
})
