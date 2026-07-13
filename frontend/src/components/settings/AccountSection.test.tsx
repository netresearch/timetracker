import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { AccountSection } from './AccountSection'

// Only the write path is mocked: the payload shape is the contract with
// PATCH /api/v2/settings. window.APP_CONFIG comes from src/test/setup.ts.
const patchSettings = vi.hoisted(() => vi.fn())
vi.mock('../../api/settings', () => ({ patchSettings }))

beforeEach(() => {
  patchSettings.mockReset()
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('AccountSection', () => {
  it('saves the five account fields via PATCH and shows the ok status', async () => {
    patchSettings.mockResolvedValue({ locale: window.APP_CONFIG!.locale })
    render(() => <AccountSection />)

    fireEvent.click(screen.getByRole('button', { name: /save|speichern/i }))

    await waitFor(() => expect(patchSettings).toHaveBeenCalledOnce())
    const payload = patchSettings.mock.calls[0]![0] as Record<string, unknown>
    expect(Object.keys(payload).sort()).toEqual([
      'locale', 'min_entry_duration', 'show_empty_line', 'show_future', 'suggest_time',
    ])
    // Personio is NOT part of this section's payload (it lives in Sync).
    expect(payload).not.toHaveProperty('personio_sync_enabled')
    expect(await screen.findByRole('status')).toBeInTheDocument()
  })

  it('reloads on a locale change', async () => {
    patchSettings.mockResolvedValue({ locale: 'fr' })
    const reload = vi.fn()
    vi.stubGlobal('location', { ...window.location, reload })
    render(() => <AccountSection />)

    fireEvent.click(screen.getByRole('button', { name: /save|speichern/i }))

    await waitFor(() => expect(reload).toHaveBeenCalled())
  })

  it('offers exactly the locales the UI ships translations for', () => {
    const { container } = render(() => <AccountSection />)

    const select = container.querySelector('select[name="locale"]') as HTMLSelectElement
    const values = Array.from(select.options).map((option) => option.value)

    // Must mirror project.inlang/settings.json (paraglide-compiled catalogs).
    expect(values).toEqual(['de', 'en', 'es', 'fr', 'ru'])
  })

  it('renders as a labeled group whose fields the save form submits', () => {
    const { getByRole, getByText } = render(() => <AccountSection />)

    const account = getByRole('group', { name: 'Account' })
    // The section states its save semantics right under the title.
    expect(getByText(/press Save to apply/)).toBeInTheDocument()

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
    // The Personio opt-in moved to the Sync section — not part of this form.
    expect(form.querySelector('input[name="personio_sync_enabled"]')).toBeNull()
  })
})
