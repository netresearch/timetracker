import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PersonioOptIn } from './PersonioOptIn'

// The settings API is mocked: patchSettings' payload shape is the write
// contract, fetchSettings is the on-mount hydration read. window.APP_CONFIG
// comes from src/test/setup.ts (personioConfigured: true, personioSyncEnabled: false).
const patchSettings = vi.hoisted(() => vi.fn())
const fetchSettings = vi.hoisted(() => vi.fn())
vi.mock('../../api/settings', () => ({ fetchSettings, patchSettings }))

beforeEach(() => {
  patchSettings.mockReset()
  fetchSettings.mockReset()
  // Default: GET echoes the APP_CONFIG snapshot (opt-in off), so hydration is a
  // no-op unless a test overrides it.
  fetchSettings.mockResolvedValue({ personio_sync_enabled: window.APP_CONFIG!.personioSyncEnabled })
})

afterEach(() => {
  cleanup()
})

describe('PersonioOptIn', () => {
  it('PATCHes only the personio field on toggle', async () => {
    patchSettings.mockResolvedValue({ personio_sync_enabled: true })
    render(() => <PersonioOptIn />)

    fireEvent.click(screen.getByRole('checkbox'))

    await waitFor(() => expect(patchSettings).toHaveBeenCalledOnce())
    // toHaveBeenCalledWith is an exact match: any extra key would fail here.
    expect(patchSettings).toHaveBeenCalledWith({ personio_sync_enabled: true })
    expect(await screen.findByRole('status')).toBeInTheDocument()
  })

  it('hydrates the checkbox from GET /api/v2/settings after mount', async () => {
    // The server holds the opt-in ON while the APP_CONFIG snapshot says OFF: the
    // GET flips the checkbox on with no user action (and no save).
    fetchSettings.mockResolvedValue({ personio_sync_enabled: true })
    render(() => <PersonioOptIn />)

    const checkbox = screen.getByRole('checkbox')
    expect(checkbox).not.toBeChecked()
    await waitFor(() => expect(checkbox).toBeChecked())
    expect(patchSettings).not.toHaveBeenCalled()
  })

  it('stays enabled and keeps focus during its own save, ignoring re-toggles', async () => {
    let resolveSave!: (value: { personio_sync_enabled: boolean }) => void
    patchSettings.mockImplementation(
      () => new Promise((resolve) => { resolveSave = resolve }),
    )
    render(() => <PersonioOptIn />)

    const checkbox = screen.getByRole('checkbox')
    checkbox.focus()
    fireEvent.click(checkbox)

    // Disabling the focused control mid-save would drop keyboard focus to
    // <body> (WCAG 2.4.3) — the control must stay enabled and focused.
    expect(checkbox).not.toBeDisabled()
    expect(document.activeElement).toBe(checkbox)

    // A toggle while the save is in flight is ignored and snapped back.
    fireEvent.click(checkbox)
    expect(patchSettings).toHaveBeenCalledOnce()
    expect(checkbox).toBeChecked()

    resolveSave({ personio_sync_enabled: true })
    expect(await screen.findByRole('status')).toBeInTheDocument()
    expect(checkbox).toBeChecked()
  })

  it('is disabled and never saves when Personio is unconfigured', () => {
    const previous = window.APP_CONFIG!.personioConfigured
    window.APP_CONFIG!.personioConfigured = false
    try {
      render(() => <PersonioOptIn />)

      expect(screen.getByRole('checkbox')).toBeDisabled()
      expect(screen.getByText(/Available once an administrator/)).toBeInTheDocument()
      expect(patchSettings).not.toHaveBeenCalled()
    } finally {
      window.APP_CONFIG!.personioConfigured = previous
    }
  })
})
