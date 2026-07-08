import { cleanup, fireEvent, render, waitFor } from '@solidjs/testing-library'
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
  vi.useRealTimers()
})

function renderField(value: string, onChange = vi.fn()): { input: HTMLInputElement; onChange: ReturnType<typeof vi.fn> } {
  const { container } = render(() => <DateField value={value} onChange={onChange} />)
  return { input: container.querySelector('input.date-field') as HTMLInputElement, onChange }
}

// Only Date is faked so zag/Ark's own timers (used by the calendar popover) keep
// running on the real clock.
function freezeClock(iso: string): void {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(`${iso}T12:00:00`))
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

  it('autocomplete completes a partial day to today\'s month/year on commit', () => {
    freezeClock('2026-07-08')
    const onChange = vi.fn()
    const { container } = render(() => <DateField value="" onChange={onChange} autocomplete />)
    const input = container.querySelector('input.date-field') as HTMLInputElement
    fireEvent.input(input, { target: { value: '7' } })
    fireEvent.change(input)
    expect(onChange).toHaveBeenCalledWith('2026-07-07')
    expect(input.value).toBe('07.07.2026')
  })

  it('shows a grey ghost of the completed date while typing', async () => {
    freezeClock('2026-07-08')
    const { container } = render(() => <DateField value="" onChange={vi.fn()} autocomplete />)
    const input = container.querySelector('input.date-field') as HTMLInputElement
    fireEvent.input(input, { target: { value: '7' } })
    await waitFor(() => {
      expect(container.querySelector('.date-field-ghost')?.textContent).toBe('07.07.2026')
    })
  })

  it('renders a persistent format hint when enhanced', () => {
    const { container } = render(() => <DateField value="" onChange={vi.fn()} autocomplete />)
    expect(container.querySelector('.field-hint')?.textContent).toBe('DD.MM.YYYY')
  })

  it('calendar trigger opens the picker and choosing a day commits its ISO', async () => {
    const onChange = vi.fn()
    // A seeded value pins the calendar to July 2026 regardless of the real clock.
    const { container } = render(() => <DateField value="2026-07-15" onChange={onChange} calendar />)
    const trigger = container.querySelector('.date-field-trigger') as HTMLButtonElement
    fireEvent.click(trigger)

    const day = await waitFor(() => {
      const el = document.querySelector('button[data-iso="2026-07-20"]')
      expect(el).not.toBeNull()

      return el as HTMLButtonElement
    })
    fireEvent.click(day)
    expect(onChange).toHaveBeenCalledWith('2026-07-20')
  })
})
