import { fireEvent, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '../test/renderWithProviders'
import Settings from './Settings'

// Only the write path is mocked: onSubmit posts through postForm, so a spy lets
// us assert the payload without a network round-trip. apiErrorMessage keeps its
// real behaviour for the error branch.
const { postFormMock } = vi.hoisted(() => ({ postFormMock: vi.fn() }))
vi.mock('../api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/client')>()

  return {
    ...actual,
    postForm: (...args: unknown[]) => postFormMock(...args) as unknown,
  }
})

beforeEach(() => {
  postFormMock.mockReset()
})

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
    for (const name of ['show_empty_line', 'suggest_time', 'show_future', 'personio_sync_enabled']) {
      expect(form.querySelector(`input[type="checkbox"][name="${name}"]`)).not.toBeNull()
    }
    expect(form.querySelector('select[name="locale"]')).not.toBeNull()
    expect(form.querySelector('input[name="min_entry_duration"]')).not.toBeNull()
    // … and the (only) Save button lives in the same card.
    expect(form.querySelector('button[type="submit"]')).not.toBeNull()

    unmount()
  })

  it('includes the Personio attendance opt-in in the save payload', async () => {
    postFormMock.mockResolvedValue(JSON.stringify({ success: true, locale: 'en', message: 'ok' }))
    const { container, unmount } = renderWithProviders(() => <Settings />)

    const checkbox = container.querySelector('input[name="personio_sync_enabled"]') as HTMLInputElement
    expect(checkbox).not.toBeNull()
    fireEvent.click(checkbox)

    const form = container.querySelector('form') as HTMLFormElement
    fireEvent.submit(form)

    await waitFor(() => expect(postFormMock).toHaveBeenCalled())
    const [url, body] = postFormMock.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/settings/save')
    expect(body).toMatchObject({ personio_sync_enabled: 1 })

    unmount()
  })

  it('preserves a persisted Personio opt-in on save when the control is disabled', async () => {
    // Personio was configured when the user opted in, then an admin removed the
    // config: the checkbox renders disabled (so the browser omits it from
    // FormData), but the persisted opt-in must survive an unrelated save rather
    // than being silently coerced off.
    const previousConfigured = window.APP_CONFIG!.personioConfigured
    const previousEnabled = window.APP_CONFIG!.personioSyncEnabled
    window.APP_CONFIG!.personioConfigured = false
    window.APP_CONFIG!.personioSyncEnabled = true
    postFormMock.mockResolvedValue(JSON.stringify({ success: true, locale: 'en', message: 'ok' }))
    try {
      const { container, unmount } = renderWithProviders(() => <Settings />)

      const checkbox = container.querySelector('input[type="checkbox"][name="personio_sync_enabled"]') as HTMLInputElement
      expect(checkbox.disabled).toBe(true)

      const form = container.querySelector('form') as HTMLFormElement
      fireEvent.submit(form)

      await waitFor(() => expect(postFormMock).toHaveBeenCalled())
      const [, body] = postFormMock.mock.calls[0] as [string, Record<string, unknown>]
      expect(body).toMatchObject({ personio_sync_enabled: 1 })

      unmount()
    } finally {
      window.APP_CONFIG!.personioConfigured = previousConfigured
      window.APP_CONFIG!.personioSyncEnabled = previousEnabled
    }
  })

  it('disables the Personio opt-in and shows the unavailable hint when Personio is not configured', () => {
    const previous = window.APP_CONFIG!.personioConfigured
    window.APP_CONFIG!.personioConfigured = false
    try {
      const { container, getByText, unmount } = renderWithProviders(() => <Settings />)

      const checkbox = container.querySelector('input[name="personio_sync_enabled"]') as HTMLInputElement
      expect(checkbox).not.toBeNull()
      expect(checkbox.disabled).toBe(true)
      expect(getByText(/Available once an administrator/)).toBeInTheDocument()

      unmount()
    } finally {
      window.APP_CONFIG!.personioConfigured = previous
    }
  })

  it('enables the Personio opt-in when Personio is configured', () => {
    const previous = window.APP_CONFIG!.personioConfigured
    window.APP_CONFIG!.personioConfigured = true
    try {
      const { container, unmount } = renderWithProviders(() => <Settings />)

      const checkbox = container.querySelector('input[name="personio_sync_enabled"]') as HTMLInputElement
      expect(checkbox).not.toBeNull()
      expect(checkbox.disabled).toBe(false)

      unmount()
    } finally {
      window.APP_CONFIG!.personioConfigured = previous
    }
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
