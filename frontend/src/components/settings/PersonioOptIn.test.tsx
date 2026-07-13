import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PersonioOptIn } from './PersonioOptIn'

// Only the write path is mocked: the payload shape is the contract with
// PATCH /api/v2/settings. window.APP_CONFIG comes from src/test/setup.ts
// (personioConfigured: true, personioSyncEnabled: false).
const patchSettings = vi.hoisted(() => vi.fn())
vi.mock('../../api/settings', () => ({ patchSettings }))

beforeEach(() => {
  patchSettings.mockReset()
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

  it('is disabled and never saves when Personio is unconfigured', () => {
    const previous = window.APP_CONFIG!.personioConfigured
    window.APP_CONFIG!.personioConfigured = false
    try {
      render(() => <PersonioOptIn />)

      expect(screen.getByRole('checkbox')).toBeDisabled()
      expect(patchSettings).not.toHaveBeenCalled()
    } finally {
      window.APP_CONFIG!.personioConfigured = previous
    }
  })
})
