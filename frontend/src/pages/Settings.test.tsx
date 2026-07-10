import { fireEvent } from '@solidjs/testing-library'
import { afterEach, describe, expect, it } from 'vitest'

import { renderWithProviders } from '../test/renderWithProviders'
import Settings from './Settings'

afterEach(() => {
  localStorage.clear()
  document.documentElement.removeAttribute('data-font')
  document.documentElement.style.removeProperty('--font-scale')
})

describe('Settings', () => {
  it('offers exactly the locales the UI ships translations for', () => {
    const { container, unmount } = renderWithProviders(() => <Settings />)

    // Target the locale select specifically (the page also has a client-side
    // grid-editing preference select).
    const select = container.querySelector('select[name="locale"]') as HTMLSelectElement
    const values = Array.from(select.options).map((option) => option.value)

    // Must mirror project.inlang/settings.json (paraglide-compiled catalogs).
    expect(values).toEqual(['de', 'en', 'es', 'fr', 'ru'])

    unmount()
  })

  it('applies the body-font preference to <html> on change', () => {
    const { container, unmount } = renderWithProviders(() => <Settings />)

    const fontSelect = Array.from(container.querySelectorAll('select')).find((select) =>
      Array.from(select.options).some((option) => option.value === 'dyslexic'),
    ) as HTMLSelectElement
    fireEvent.change(fontSelect, { target: { value: 'dyslexic' } })

    expect(document.documentElement.getAttribute('data-font')).toBe('dyslexic')
    expect(localStorage.getItem('timetracker-font')).toBe('dyslexic')

    unmount()
  })
})

describe('Settings grouping', () => {
  // The page splits into two labeled sections (fieldset + legend) with
  // opposite save semantics: account-saved (needs Save) vs device-local
  // (applies instantly). Tests run under the base locale (en).
  it('renders the account and device sections as labeled groups', () => {
    const { getByRole, getByText, unmount } = renderWithProviders(() => <Settings />)

    expect(getByRole('group', { name: 'Account' })).toBeInTheDocument()
    expect(getByRole('group', { name: 'This device' })).toBeInTheDocument()

    // Each section states its save semantics right under the title.
    expect(getByText(/press Save to apply/)).toBeInTheDocument()
    expect(getByText(/apply immediately — no Save needed/)).toBeInTheDocument()

    unmount()
  })

  it('scopes the Save button and the account-saved fields to the save form', () => {
    const { getByRole, unmount } = renderWithProviders(() => <Settings />)

    const account = getByRole('group', { name: 'Account' })
    const form = account.closest('form') as HTMLFormElement
    expect(form).not.toBeNull()

    // Every account-saved setting is submitted by this form …
    for (const name of ['show_empty_line', 'suggest_time', 'show_future']) {
      expect(form.querySelector(`input[type="checkbox"][name="${name}"]`)).not.toBeNull()
    }
    expect(form.querySelector('select[name="locale"]')).not.toBeNull()
    expect(form.querySelector('input[name="min_entry_duration"]')).not.toBeNull()
    // … and the (only) Save button lives in the same card.
    expect(form.querySelector('button[type="submit"]')).not.toBeNull()

    unmount()
  })

  it('keeps the instantly-applied device preferences outside the save form', () => {
    const { getByRole, unmount } = renderWithProviders(() => <Settings />)

    const device = getByRole('group', { name: 'This device' })

    // Device-local preferences are deliberately not part of any form — they
    // persist to localStorage on change, nothing here is submitted.
    expect(device.closest('form')).toBeNull()
    expect(device.querySelector('button[type="submit"]')).toBeNull()
    // All five live here: Enter behavior, date format, font, text size, layout.
    expect(device.querySelectorAll('select').length).toBe(5)

    unmount()
  })
})
