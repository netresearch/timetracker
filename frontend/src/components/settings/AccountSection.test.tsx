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
})
