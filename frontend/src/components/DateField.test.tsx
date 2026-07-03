import { cleanup, fireEvent, render } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AppConfig } from '../config'
import { setDateFormat } from '../lib/dateFormat'
import { DateField } from './DateField'

const appConfigStub: AppConfig = {
  locale: 'de', userId: 1, userName: 'x', appTitle: '', roles: [],
  showEmptyLine: false, suggestTime: false, showFuture: false, minEntryDuration: 5, logoutUrl: '',
  csrfToken: '', loginPath: '/login', totpEnabled: false, localAccount: true,
  twoFactorRequired: false, hasTwoFactor: false,
}

beforeEach(() => {
  window.APP_CONFIG = { ...appConfigStub }
  setDateFormat({ mode: 'custom', pattern: 'DD.MM.YYYY' })
})
afterEach(() => {
  cleanup()
})

function renderField(value: string, onChange = vi.fn()): { input: HTMLInputElement; onChange: ReturnType<typeof vi.fn> } {
  const { container } = render(() => <DateField value={value} onChange={onChange} />)
  return { input: container.querySelector('input.date-field') as HTMLInputElement, onChange }
}

describe('DateField', () => {
  it('displays the ISO value in the configured format, with a matching placeholder', () => {
    const { input } = renderField('2026-06-19')
    expect(input.value).toBe('19.06.2026')
    expect(input.placeholder).toBe('DD.MM.YYYY')
  })

  it('parses a value typed in the configured format back to ISO', () => {
    const { input, onChange } = renderField('2026-06-19')
    fireEvent.input(input, { target: { value: '25.12.2026' } })
    fireEvent.change(input)
    expect(onChange).toHaveBeenCalledWith('2026-12-25')
  })

  it('accepts ISO directly as a fallback', () => {
    const { input, onChange } = renderField('')
    fireEvent.input(input, { target: { value: '2026-12-25' } })
    fireEvent.change(input)
    expect(onChange).toHaveBeenCalledWith('2026-12-25')
  })

  it('clears to empty when blanked', () => {
    const { input, onChange } = renderField('2026-06-19')
    fireEvent.input(input, { target: { value: '' } })
    fireEvent.change(input)
    expect(onChange).toHaveBeenCalledWith('')
  })

  it('flags an unparseable value (aria-invalid) without committing', () => {
    const { input, onChange } = renderField('')
    fireEvent.input(input, { target: { value: 'not a date' } })
    fireEvent.change(input)
    expect(onChange).not.toHaveBeenCalled()
    expect(input.getAttribute('aria-invalid')).toBe('true')
  })
})
