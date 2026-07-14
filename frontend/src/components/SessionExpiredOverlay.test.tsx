import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Passkeys (WebAuthn) — unsupported in jsdom; stub so the explicit button can be
// exercised. The default keeps the button hidden (passkeysSupported → false),
// matching real jsdom, so the password/2FA tests behave exactly as before.
const passkeysSupported = vi.fn(() => false)
const passkeyAutofillSupported = vi.fn(() => Promise.resolve(false))
const loginWithPasskey = vi.fn<(...args: unknown[]) => Promise<string>>(() => Promise.resolve('/'))
const loginWithPasskeyAutofill = vi.fn<(...args: unknown[]) => Promise<string>>(() => Promise.resolve('/'))
const cancelPasskeyCeremony = vi.fn()

vi.mock('../lib/passkeys', () => ({
  passkeysSupported: () => passkeysSupported(),
  passkeyAutofillSupported: () => passkeyAutofillSupported(),
  loginWithPasskey: (...args: unknown[]) => loginWithPasskey(...args),
  loginWithPasskeyAutofill: (...args: unknown[]) => loginWithPasskeyAutofill(...args),
  cancelPasskeyCeremony: () => cancelPasskeyCeremony(),
}))

const { SessionExpiredOverlay } = await import('./SessionExpiredOverlay')

beforeEach(() => {
  // Reset call history too (the overlay calls cancelPasskeyCeremony on every
  // teardown, so counts would accumulate across tests otherwise).
  passkeysSupported.mockReset().mockReturnValue(false)
  passkeyAutofillSupported.mockReset().mockResolvedValue(false)
  loginWithPasskey.mockReset().mockResolvedValue('/')
  loginWithPasskeyAutofill.mockReset().mockResolvedValue('/')
  cancelPasskeyCeremony.mockClear()
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

// Stub the re-login XHR; the body is read as JSON ({ok}).
function mockLoginFetch(ok: boolean): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({
    ok,
    status: ok ? 200 : 401,
    json: async () => ({ ok }),
  })
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

// The password POST (→ /login) reports 2FA required; the code POST (→ /2fa_check)
// yields codeOk. Mirrors the JSON contract of TwoFactorJsonRequired/SuccessHandler.
function mockTwoFactorFetch(codeOk: boolean): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockImplementation((url: string) =>
    Promise.resolve(
      String(url) === '/2fa_check'
        ? { ok: codeOk, status: codeOk ? 200 : 401, json: async () => ({ ok: codeOk }) }
        : { ok: false, status: 401, json: async () => ({ ok: false, twoFactorRequired: true }) },
    ),
  )
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

describe('SessionExpiredOverlay', () => {
  it('re-logs in via XHR with the LoginForm contract and calls onSuccess on {ok:true}', async () => {
    const fetchMock = mockLoginFetch(true)
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    expect(screen.getByText('Session expired')).toBeInTheDocument()
    // Username pre-filled from APP_CONFIG.userName (test setup = 'unittest').
    expect((screen.getByLabelText('Username') as HTMLInputElement).value).toBe('unittest')

    fireEvent.input(screen.getByLabelText('Password'), { target: { value: 'secret' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(onSuccess).toHaveBeenCalledTimes(1))
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/login')
    expect(init.method).toBe('POST')
    expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest')
    const body = String(init.body)
    expect(body).toContain('_username=unittest')
    expect(body).toContain('_password=secret')
    expect(body).toContain('_csrf_token=test-csrf-token')
  })

  it('shows an inline error and does not call onSuccess on {ok:false}', async () => {
    mockLoginFetch(false)
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    fireEvent.input(screen.getByLabelText('Password'), { target: { value: 'wrong' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(onSuccess).not.toHaveBeenCalled()
  })

  it('offers a full-page login fallback link', () => {
    mockLoginFetch(true)
    render(() => <SessionExpiredOverlay onSuccess={vi.fn()} />)
    const link = screen.getByRole('link', { name: /login page/i })
    expect(link.getAttribute('href')).toBe('/login')
  })

  it('treats a 2FA-required password as success: swaps to the code step, then resumes on a valid code', async () => {
    // Regression: a correct LDAP password on a TOTP-enrolled account was mislabelled
    // "login failed" because the overlay had no twoFactorRequired branch.
    const fetchMock = mockTwoFactorFetch(true)
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    fireEvent.input(screen.getByLabelText('Password'), { target: { value: 'ldap-pass' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    // Password accepted → code step appears; no error, no premature resume.
    await waitFor(() => expect(screen.getByLabelText('Verification code')).toBeInTheDocument())
    expect(onSuccess).not.toHaveBeenCalled()
    expect(screen.queryByRole('alert')).toBeNull()

    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Verify' }))

    await waitFor(() => expect(onSuccess).toHaveBeenCalledTimes(1))
    const [codeUrl, codeInit] = fetchMock.mock.calls[1]!
    expect(codeUrl).toBe('/2fa_check')
    expect(String(codeInit.body)).toContain('_auth_code=123456')
  })

  it('surfaces a 2FA code error without resuming', async () => {
    mockTwoFactorFetch(false)
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    fireEvent.input(screen.getByLabelText('Password'), { target: { value: 'ldap-pass' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    await waitFor(() => expect(screen.getByLabelText('Verification code')).toBeInTheDocument())

    fireEvent.input(screen.getByLabelText('Verification code'), { target: { value: '000000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Verify' }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(onSuccess).not.toHaveBeenCalled()
  })

  it('hides the passkey button when WebAuthn is unsupported', () => {
    // passkeysSupported defaults to false (jsdom parity).
    render(() => <SessionExpiredOverlay onSuccess={vi.fn()} />)
    expect(screen.queryByRole('button', { name: 'Sign in with a passkey' })).toBeNull()
  })

  it('offers a passkey button that resumes in place on success', async () => {
    passkeysSupported.mockReturnValue(true)
    loginWithPasskey.mockResolvedValue('/ui/tracking')
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    fireEvent.click(screen.getByRole('button', { name: 'Sign in with a passkey' }))

    await waitFor(() => expect(onSuccess).toHaveBeenCalledTimes(1))
    expect(loginWithPasskey).toHaveBeenCalledTimes(1)
    // Mirrors the overlay's password path (which sends no _remember_me): the
    // overlay only shows when no valid REMEMBERME cookie exists, so a re-login
    // must not silently grant a 30-day cookie the user never opted into (#587).
    const remember = loginWithPasskey.mock.calls[0]![0] as () => boolean
    expect(remember()).toBe(false)
  })

  it('surfaces an inline error when the passkey ceremony fails, without resuming', async () => {
    passkeysSupported.mockReturnValue(true)
    loginWithPasskey.mockRejectedValue(new Error('user dismissed'))
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    fireEvent.click(screen.getByRole('button', { name: 'Sign in with a passkey' }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(onSuccess).not.toHaveBeenCalled()
  })

  it('starts passkey autofill (conditional UI) on mount and resumes in place when one is picked', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    loginWithPasskeyAutofill.mockResolvedValue('/ui/tracking')
    const onSuccess = vi.fn()
    render(() => <SessionExpiredOverlay onSuccess={onSuccess} />)

    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(onSuccess).toHaveBeenCalledTimes(1))
  })

  it('tears down the passkey autofill ceremony on unmount', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    // Never resolves — the background ceremony stays pending until torn down.
    loginWithPasskeyAutofill.mockReturnValue(new Promise<string>(() => {}))
    const { unmount } = render(() => <SessionExpiredOverlay onSuccess={vi.fn()} />)

    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())
    unmount()
    expect(cancelPasskeyCeremony).toHaveBeenCalled()
  })

  it('passes an AbortSignal to the autofill ceremony so it can be aborted', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    loginWithPasskeyAutofill.mockReturnValue(new Promise<string>(() => {}))
    render(() => <SessionExpiredOverlay onSuccess={vi.fn()} />)

    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())
    const [remember, signal] = loginWithPasskeyAutofill.mock.calls[0]!
    expect(signal).toBeInstanceOf(AbortSignal)
    // Same remember-me parity as the explicit passkey button (#587).
    expect((remember as () => boolean)()).toBe(false)
  })

  it('tears down the autofill when switching to the 2FA code step', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    loginWithPasskeyAutofill.mockReturnValue(new Promise<string>(() => {}))
    mockTwoFactorFetch(true) // password accepted → 2FA required
    render(() => <SessionExpiredOverlay onSuccess={vi.fn()} />)
    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())

    fireEvent.input(screen.getByLabelText('Password'), { target: { value: 'ldap-pass' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    // Entering the code phase must abort the pending autofill (cancelCeremony),
    // so a late passkey pick can't resume the session mid-2FA.
    await waitFor(() => expect(screen.getByLabelText('Verification code')).toBeInTheDocument())
    expect(cancelPasskeyCeremony).toHaveBeenCalled()
  })
})
