import { beforeEach, describe, expect, it } from 'vitest'

import type { AppConfig } from '../config'
import { formatUserDate, formatWith, setDateFormat, validatePattern } from './dateFormat'

const ISO = '2026-06-19'

const appConfigStub: AppConfig = {
  locale: 'de', userId: 1, userName: 'x', appTitle: '', roles: [],
  showEmptyLine: false, suggestTime: false, showFuture: false, minEntryDuration: 5, logoutUrl: '', legacyUrl: '',
  csrfToken: '', loginPath: '/login',
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
})
