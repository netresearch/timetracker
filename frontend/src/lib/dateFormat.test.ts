import { beforeEach, describe, expect, it } from 'vitest'

import type { AppConfig } from '../config'
import { dateFormatPlaceholder, formatUserDate, formatWith, parseUserDate, setDateFormat, validatePattern } from './dateFormat'

const ISO = '2026-06-19'

const appConfigStub: AppConfig = {
  locale: 'de', userId: 1, userName: 'x', appTitle: '', roles: [],
  showEmptyLine: false, suggestTime: false, showFuture: false, minEntryDuration: 5, personioSyncEnabled: false, logoutUrl: '',
  csrfToken: '', loginPath: '/login', totpEnabled: false, localAccount: true,
  twoFactorRequired: false, hasTwoFactor: false,
}

describe('dateFormat', () => {
  describe('formatWith — iso & custom (pure)', () => {
    it('iso mode passes the ISO value through', () => {
      expect(formatWith(ISO, { mode: 'iso', pattern: 'DD.MM.YYYY' })).toBe(ISO)
    })
    it('custom DD.MM.YYYY', () => {
      expect(formatWith(ISO, { mode: 'custom', pattern: 'DD.MM.YYYY' })).toBe('19.06.2026')
    })
    it('custom strftime aliases %d/%m/%Y', () => {
      expect(formatWith(ISO, { mode: 'custom', pattern: '%d/%m/%Y' })).toBe('19/06/2026')
    })
    it('short tokens YY-M-D strip padding', () => {
      expect(formatWith(ISO, { mode: 'custom', pattern: 'YY-M-D' })).toBe('26-6-19')
    })
    it('non-token characters are emitted verbatim', () => {
      expect(formatWith(ISO, { mode: 'custom', pattern: 'YYYY / DD' })).toBe('2026 / 19')
    })
    it('an invalid custom pattern falls back to ISO', () => {
      expect(formatWith(ISO, { mode: 'custom', pattern: 'hello' })).toBe(ISO)
    })
    it('a non-ISO input is passed through untouched', () => {
      expect(formatWith('19/06/2026', { mode: 'custom', pattern: 'DD' })).toBe('19/06/2026')
      expect(formatWith('', { mode: 'auto', pattern: '' })).toBe('')
    })
  })

  describe('validatePattern', () => {
    it('rejects empty / whitespace', () => {
      expect(validatePattern('   ').ok).toBe(false)
    })
    it('rejects > 32 chars (the ReDoS-safe bound)', () => {
      expect(validatePattern('D'.repeat(40)).ok).toBe(false)
    })
    it('rejects a pattern with no day/month/year token', () => {
      expect(validatePattern('xyz').ok).toBe(false)
    })
    it('accepts a pattern with at least one token', () => {
      expect(validatePattern('DD.MM.YYYY').ok).toBe(true)
      expect(validatePattern('%Y').ok).toBe(true)
    })
  })

  describe('auto mode + the reactive setter', () => {
    beforeEach(() => {
      window.APP_CONFIG = { ...appConfigStub }
    })

    it('auto formats via Intl in the app locale (non-ISO, keeps the year)', () => {
      const out = formatWith(ISO, { mode: 'auto', pattern: '' })
      expect(out).not.toBe(ISO)
      expect(out).toMatch(/2026/)
    })
    it('auto falls back to ISO when APP_CONFIG is absent (never throws)', () => {
      delete window.APP_CONFIG
      expect(formatWith(ISO, { mode: 'auto', pattern: '' })).toBe(ISO)
    })
    it('setDateFormat drives formatUserDate', () => {
      setDateFormat({ mode: 'custom', pattern: 'DD.MM.YYYY' })
      expect(formatUserDate(ISO)).toBe('19.06.2026')
      setDateFormat({ mode: 'iso', pattern: 'DD.MM.YYYY' })
      expect(formatUserDate(ISO)).toBe(ISO)
    })
  })

  describe('parseUserDate (form save path)', () => {
    beforeEach(() => {
      window.APP_CONFIG = { ...appConfigStub }
    })

    const custom = { mode: 'custom', pattern: 'DD.MM.YYYY' } as const

    it('parses the configured custom format back to ISO', () => {
      expect(parseUserDate('19.06.2026', custom)).toBe(ISO)
    })
    it('pads single-digit day/month', () => {
      expect(parseUserDate('5.6.2026', custom)).toBe('2026-06-05')
    })
    it('expands a 2-digit year to 20YY', () => {
      expect(parseUserDate('19.06.26', { mode: 'custom', pattern: 'DD.MM.YY' })).toBe(ISO)
    })
    it('ALWAYS accepts ISO as a fallback, whatever the format', () => {
      expect(parseUserDate('2026-06-19', custom)).toBe(ISO)
      expect(parseUserDate('2026-06-19', { mode: 'iso', pattern: '' })).toBe(ISO)
    })
    it('auto mode parses the locale order (de → d.m.y)', () => {
      expect(parseUserDate('19.06.2026', { mode: 'auto', pattern: '' })).toBe(ISO)
    })
    it('blank input parses to empty (clears an optional date)', () => {
      expect(parseUserDate('   ', custom)).toBe('')
    })
    it('rejects an impossible date (Feb 30)', () => {
      expect(parseUserDate('30.02.2026', custom)).toBeNull()
    })
    it('rejects garbage and a non-ISO string in iso mode', () => {
      expect(parseUserDate('not a date', custom)).toBeNull()
      expect(parseUserDate('19.06.2026', { mode: 'iso', pattern: '' })).toBeNull()
    })
  })

  describe('dateFormatPlaceholder', () => {
    it('shows the pattern for custom, YYYY-MM-DD for iso, a sample for auto', () => {
      window.APP_CONFIG = { ...appConfigStub }
      expect(dateFormatPlaceholder({ mode: 'custom', pattern: 'DD.MM.YYYY' })).toBe('DD.MM.YYYY')
      expect(dateFormatPlaceholder({ mode: 'iso', pattern: '' })).toBe('YYYY-MM-DD')
      expect(dateFormatPlaceholder({ mode: 'auto', pattern: '' })).toMatch(/\d/)
    })
  })
})
