import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const postJson = vi.fn()

vi.mock('../api/client', () => ({
  ApiError: class extends Error {},
  ValidationError: class extends Error {},
  SessionExpiredError: class extends Error {},
  apiErrorMessage: (error: unknown, fallback: string) => (error instanceof Error && error.message ? error.message : fallback),
  postJson: (...args: unknown[]) => postJson(...args),
}))

const registerPasskey = vi.fn()
const listPasskeys = vi.fn()
let passkeysAvailable = false

vi.mock('../lib/passkeys', () => ({
  passkeysSupported: () => passkeysAvailable,
  registerPasskey: (...a: unknown[]) => registerPasskey(...a),
  deletePasskey: vi.fn(),
  listPasskeys: (...a: unknown[]) => listPasskeys(...a),
}))

const { TwoFactorGate } = await import('./TwoFactorGate')

describe('TwoFactorGate', () => {
  const reload = vi.fn()

  beforeEach(() => {
    postJson.mockReset()
    registerPasskey.mockReset()
    listPasskeys.mockReset().mockResolvedValue([])
    reload.mockReset()
    passkeysAvailable = false
    vi.stubGlobal('location', { ...window.location, reload })
  })
  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('explains the requirement and offers enrolment plus a logout escape', () => {
    render(() => <TwoFactorGate />)

    expect(screen.getByRole('heading', { name: 'Set up two-factor authentication' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Enable two-factor' })).toBeTruthy()
    const logout = screen.getByRole('link', { name: 'Sign out instead' }) as HTMLAnchorElement
    // The escape hatch points at the server logout URL from APP_CONFIG.
    expect(logout.getAttribute('href')).toBe('/logout')
  })

  it('reloads into the app once TOTP enrolment is finished', async () => {
    postJson
      .mockResolvedValueOnce({ provisioningUri: 'otpauth://totp/x', secret: 'JBSWY3DPEHPK3PXP' })
      .mockResolvedValueOnce({ enabled: true, backupCodes: ['aaaa-1111'] })
    render(() => <TwoFactorGate />)

    fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }))
    await waitFor(() => screen.getByLabelText('Verification code'))
    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }))

    // Backup codes show first; dismissing them completes enrolment and reloads.
    await waitFor(() => expect(screen.getByText('aaaa-1111')).toBeTruthy())
    expect(reload).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: "I've saved them" }))
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('reloads into the app after a passkey is registered', async () => {
    passkeysAvailable = true
    registerPasskey.mockResolvedValue(undefined)
    render(() => <TwoFactorGate />)

    fireEvent.click(await screen.findByRole('button', { name: 'Add a passkey' }))

    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1))
  })
})
