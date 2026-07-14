import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { AccountSection } from './AccountSection'

// The settings API is mocked: patchSettings' payload shape is the write
// contract, fetchSettings is the on-mount hydration read. window.APP_CONFIG
// comes from src/test/setup.ts.
const patchSettings = vi.hoisted(() => vi.fn())
const fetchSettings = vi.hoisted(() => vi.fn())
vi.mock('../../api/settings', () => ({ fetchSettings, patchSettings }))

beforeEach(() => {
  patchSettings.mockReset()
  fetchSettings.mockReset()
  // Default: GET echoes the APP_CONFIG snapshot, so hydration is a no-op unless
  // a test overrides it.
  fetchSettings.mockResolvedValue({
    locale: window.APP_CONFIG!.locale,
    show_empty_line: window.APP_CONFIG!.showEmptyLine,
    suggest_time: window.APP_CONFIG!.suggestTime,
    show_future: window.APP_CONFIG!.showFuture,
    min_entry_duration: window.APP_CONFIG!.minEntryDuration,
    personio_sync_enabled: window.APP_CONFIG!.personioSyncEnabled,
  })
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

  it('hydrates the form from GET /api/v2/settings after mount', async () => {
    // The server holds values that differ from the APP_CONFIG snapshot (which is
    // all-false / 5): after the GET resolves the inputs reflect the persisted
    // state, not the stale pre-fetch defaults.
    fetchSettings.mockResolvedValue({
      locale: 'en',
      show_empty_line: true,
      suggest_time: true,
      show_future: true,
      min_entry_duration: 30,
      personio_sync_enabled: false,
    })
    const { container } = render(() => <AccountSection />)

    const showFuture = container.querySelector('input[name="show_future"]') as HTMLInputElement
    // Pre-fetch it is unchecked (APP_CONFIG default); the GET flips it on.
    expect(showFuture.checked).toBe(false)
    await waitFor(() => expect(showFuture.checked).toBe(true))
    expect((container.querySelector('input[name="suggest_time"]') as HTMLInputElement).checked).toBe(true)
    expect(container.querySelector('input[name="min_entry_duration"]')).toHaveValue(30)
  })

  it('keeps an edit made while the on-mount GET is still resolving', async () => {
    // Server holds a locale ('fr') that differs from both the APP_CONFIG snapshot
    // ('en') and the value the user is about to pick ('de').
    fetchSettings.mockResolvedValue({
      locale: 'fr',
      show_empty_line: false,
      suggest_time: false,
      show_future: false,
      min_entry_duration: 5,
      personio_sync_enabled: false,
    })
    const { container } = render(() => <AccountSection />)

    const select = container.querySelector('select[name="locale"]') as HTMLSelectElement
    expect(select.value).toBe('en')

    // The user changes the locale synchronously — i.e. before the resolved GET's
    // microtask runs — during the hydration fetch window.
    fireEvent.input(select, { target: { value: 'de' } })
    expect(select.value).toBe('de')

    // Once the GET resolves ('fr'), hydration must not clobber the user's choice.
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(select.value).toBe('de')
  })

  it('offers exactly the locales the UI ships translations for', () => {
    const { container } = render(() => <AccountSection />)

    const select = container.querySelector('select[name="locale"]') as HTMLSelectElement
    const values = Array.from(select.options).map((option) => option.value)

    // Must mirror project.inlang/settings.json (paraglide-compiled catalogs).
    expect(values).toEqual(['de', 'en', 'es', 'fr', 'ru'])
  })

  it('renders as a labeled group whose fields the save form submits', async () => {
    const { container, getByRole, getByText } = render(() => <AccountSection />)

    const account = getByRole('group', { name: 'Account' })
    // The h2 lives inside the legend: the same node names the group and enters
    // the page outline, so the title is announced once, not twice.
    expect(getByRole('heading', { level: 2, name: 'Account' })).toBeInTheDocument()
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

    expect(await axe(container)).toHaveNoViolations()
  })
})
