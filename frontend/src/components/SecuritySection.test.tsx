import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const postJson = vi.fn()

class MockApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message)
  }
}

vi.mock('../api/client', () => ({
  ApiError: MockApiError,
  ValidationError: class extends Error {},
  SessionExpiredError: class extends Error {},
  apiErrorMessage: (error: unknown, fallback: string) => (error instanceof Error && error.message ? error.message : fallback),
  postJson: (...args: unknown[]) => postJson(...args),
}))

// Passkeys (WebAuthn) — unsupported in jsdom, so the browser ceremony is stubbed.
const registerPasskey = vi.fn()
const deletePasskey = vi.fn()
const listPasskeys = vi.fn()
let passkeysAvailable = false

vi.mock('../lib/passkeys', () => ({
  passkeysSupported: () => passkeysAvailable,
  registerPasskey: (...a: unknown[]) => registerPasskey(...a),
  deletePasskey: (...a: unknown[]) => deletePasskey(...a),
  listPasskeys: (...a: unknown[]) => listPasskeys(...a),
}))

// Imported after the mock so the component picks up the stubbed client.
const { SecuritySection } = await import('./SecuritySection')

/** Reset APP_CONFIG to the state each test needs (setup.ts seeds a base object). */
function configure(overrides: { totpEnabled?: boolean; localAccount?: boolean }): void {
  window.APP_CONFIG = { ...window.APP_CONFIG!, totpEnabled: false, localAccount: true, ...overrides }
}

describe('SecuritySection', () => {
  beforeEach(() => {
    postJson.mockReset()
    registerPasskey.mockReset()
    deletePasskey.mockReset()
    listPasskeys.mockReset().mockResolvedValue([])
    passkeysAvailable = false
    configure({})
  })
  afterEach(cleanup)

  it('shows the password form for a local account', () => {
    render(() => <SecuritySection />)
    expect(screen.getByLabelText('Current password')).toBeTruthy()
  })

  it('hides the password form for an LDAP account', () => {
    configure({ localAccount: false })
    render(() => <SecuritySection />)
    expect(screen.queryByLabelText('Current password')).toBeNull()
  })

  it('rejects mismatched new passwords without calling the API', async () => {
    render(() => <SecuritySection />)
    fireEvent.input(screen.getByLabelText('Current password'), { target: { value: 'oldpass12' } })
    fireEvent.input(screen.getByLabelText('New password'), { target: { value: 'brandnew34' } })
    fireEvent.input(screen.getByLabelText('Confirm new password'), { target: { value: 'different99' } })
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }))

    await waitFor(() => expect(screen.getByText('The new passwords do not match.')).toBeTruthy())
    expect(postJson).not.toHaveBeenCalled()
  })

  it('changes the password with the correct payload', async () => {
    postJson.mockResolvedValueOnce({ success: true })
    render(() => <SecuritySection />)
    fireEvent.input(screen.getByLabelText('Current password'), { target: { value: 'oldpass12' } })
    fireEvent.input(screen.getByLabelText('New password'), { target: { value: 'brandnew34' } })
    fireEvent.input(screen.getByLabelText('Confirm new password'), { target: { value: 'brandnew34' } })
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }))

    await waitFor(() => expect(screen.getByText('Your password has been changed.')).toBeTruthy())
    expect(postJson).toHaveBeenCalledWith('/settings/password', { currentPassword: 'oldpass12', newPassword: 'brandnew34' })
  })

  it('enrols in TOTP and reveals the backup codes exactly once', async () => {
    postJson
      .mockResolvedValueOnce({ provisioningUri: 'otpauth://totp/x', secret: 'JBSWY3DPEHPK3PXP' })
      .mockResolvedValueOnce({ enabled: true, backupCodes: ['aaaa-1111', 'bbbb-2222'] })
    render(() => <SecuritySection />)

    fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }))
    await waitFor(() => expect((screen.getByLabelText('Setup key') as HTMLInputElement).value).toBe('JBSWY3DPEHPK3PXP'))
    expect(postJson).toHaveBeenNthCalledWith(1, '/settings/2fa/totp/start', {})

    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }))

    await waitFor(() => expect(screen.getByText('aaaa-1111')).toBeTruthy())
    expect(screen.getByText('bbbb-2222')).toBeTruthy()
    expect(postJson).toHaveBeenNthCalledWith(2, '/settings/2fa/totp/confirm', { code: '123456' })

    // Dismissing the codes reveals the enabled state.
    fireEvent.click(screen.getByRole('button', { name: "I've saved them" }))
    await waitFor(() => expect(screen.getByText('Two-factor authentication is on.')).toBeTruthy())
  })

  it('clears a typed code when enrolment is cancelled', async () => {
    postJson.mockResolvedValueOnce({ provisioningUri: 'otpauth://totp/x', secret: 'SECRET1' })
    render(() => <SecuritySection />)
    fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }))
    await waitFor(() => screen.getByLabelText('Verification code'))
    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '999999' } })
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    // A fresh enrolment must not pre-fill the previously typed code.
    postJson.mockResolvedValueOnce({ provisioningUri: 'otpauth://totp/y', secret: 'SECRET2' })
    fireEvent.click(screen.getByRole('button', { name: 'Enable two-factor' }))
    await waitFor(() => expect((screen.getByLabelText('Verification code') as HTMLInputElement).value).toBe(''))
  })

  it('hides the passkey section when the browser has no WebAuthn support', () => {
    passkeysAvailable = false
    render(() => <SecuritySection />)
    expect(screen.queryByRole('button', { name: 'Add a passkey' })).toBeNull()
  })

  it('registers a passkey and refreshes the list', async () => {
    passkeysAvailable = true
    listPasskeys.mockResolvedValueOnce([]).mockResolvedValueOnce([{ id: 7, fingerprint: 'abcdef0123', transports: ['internal'] }])
    registerPasskey.mockResolvedValue(undefined)
    render(() => <SecuritySection />)

    fireEvent.click(await screen.findByRole('button', { name: 'Add a passkey' }))

    await waitFor(() => expect(registerPasskey).toHaveBeenCalled())
    // The refreshed list renders the new passkey with a remove control.
    await waitFor(() => expect(screen.getByRole('button', { name: 'Remove' })).toBeTruthy())
  })

  it('removes a passkey', async () => {
    passkeysAvailable = true
    listPasskeys.mockResolvedValue([{ id: 7, fingerprint: 'abcdef0123', transports: ['internal'] }])
    deletePasskey.mockResolvedValue(undefined)
    render(() => <SecuritySection />)

    fireEvent.click(await screen.findByRole('button', { name: 'Remove' }))

    await waitFor(() => expect(deletePasskey).toHaveBeenCalledWith(7))
  })

  it('requires a re-auth code to disable TOTP, then disables', async () => {
    configure({ totpEnabled: true })
    postJson.mockResolvedValueOnce({ enabled: false })
    render(() => <SecuritySection />)

    expect(screen.getByText('Two-factor authentication is on.')).toBeTruthy()
    // First click only reveals the code step (ADR-018 D4) — no request yet.
    fireEvent.click(screen.getByRole('button', { name: 'Disable two-factor' }))
    expect(postJson).not.toHaveBeenCalled()

    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '654321' } })
    fireEvent.click(screen.getByRole('button', { name: 'Disable two-factor' }))

    await waitFor(() => expect(screen.getByRole('button', { name: 'Enable two-factor' })).toBeTruthy())
    expect(postJson).toHaveBeenCalledWith('/settings/2fa/disable', { code: '654321' })
  })

  it('cancels the disable step without calling the API', () => {
    configure({ totpEnabled: true })
    render(() => <SecuritySection />)

    fireEvent.click(screen.getByRole('button', { name: 'Disable two-factor' }))
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(postJson).not.toHaveBeenCalled()
    expect(screen.getByText('Two-factor authentication is on.')).toBeTruthy()
  })

  it('keeps 2FA on and shows a localized error when the disable code is rejected', async () => {
    configure({ totpEnabled: true })
    // The server answers 422 with no prose; the SPA supplies the localized message.
    postJson.mockRejectedValueOnce(new MockApiError(422, ''))
    render(() => <SecuritySection />)

    fireEvent.click(screen.getByRole('button', { name: 'Disable two-factor' }))
    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '000000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Disable two-factor' }))

    await waitFor(() => expect(screen.getByText(/That code wasn't right/)).toBeTruthy())
    // Rejected re-auth leaves 2FA on: no flip to the enable state.
    expect(screen.queryByRole('button', { name: 'Enable two-factor' })).toBeNull()
    expect(postJson).toHaveBeenCalledWith('/settings/2fa/disable', { code: '000000' })
  })
})
