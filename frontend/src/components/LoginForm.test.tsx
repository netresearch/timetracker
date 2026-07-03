import { cleanup, fireEvent, render, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { ApiError } from '../api/client'
import type { LoginConfig } from '../loginConfig'

// Passkeys (WebAuthn) — unsupported in jsdom, so the ceremonies are stubbed.
// Defaults keep the passkey UI hidden and autofill inert, matching real jsdom,
// so tests that don't opt in behave exactly as before.
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

const { LoginForm } = await import('./LoginForm')

// A microtask flush so an onMount async catch settles before an absence assert.
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const config: LoginConfig = {
  locale: 'en',
  appTitle: 'TimeTracker',
  logoUrl: '/logo.svg',
  csrfToken: 'csrf-123',
  loginPath: '/login',
  twoFactorPath: '/2fa_check',
  lastUsername: '',
  error: null,
}

function mockFetchOnce(status: number, body: unknown) {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  })
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

beforeEach(() => {
  passkeysSupported.mockReset().mockReturnValue(false)
  passkeyAutofillSupported.mockReset().mockResolvedValue(false)
  loginWithPasskey.mockReset().mockResolvedValue('/')
  loginWithPasskeyAutofill.mockReset().mockResolvedValue('/')
  cancelPasskeyCeremony.mockReset()
})

afterEach(() => {
  cleanup() // remove each render's DOM so landmarks (a11y) and state don't leak
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('LoginForm', () => {
  it('marks the username field for passkey autofill (webauthn autocomplete token)', () => {
    const { container, unmount } = render(() => <LoginForm config={config} />)

    expect(container.querySelector('input[name="_username"]')).toHaveAttribute('autocomplete', 'username webauthn')

    unmount()
  })

  it('starts conditional-UI passkey autofill on mount and redirects when one is chosen', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    passkeyAutofillSupported.mockResolvedValue(true)
    loginWithPasskeyAutofill.mockResolvedValue('/ui/tracking')

    const { unmount } = render(() => <LoginForm config={config} />)

    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/ui/tracking'))

    unmount()
  })

  it('leaves the password form usable when autofill is unsupported or unused', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    passkeyAutofillSupported.mockResolvedValue(false)

    const { getByRole, unmount } = render(() => <LoginForm config={config} />)

    await waitFor(() => expect(passkeyAutofillSupported).toHaveBeenCalled())
    expect(loginWithPasskeyAutofill).not.toHaveBeenCalled()
    expect(assign).not.toHaveBeenCalled()
    expect(getByRole('button', { name: 'Sign in' })).toBeInTheDocument()

    unmount()
  })

  it('surfaces an error when the server rejects a passkey the user selected (ApiError)', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    loginWithPasskeyAutofill.mockRejectedValue(new ApiError(401, 'nope'))

    const { getByRole, unmount } = render(() => <LoginForm config={config} />)

    await waitFor(() => expect(getByRole('alert')).toBeInTheDocument())

    unmount()
  })

  it('stays silent when the autofill ceremony is aborted or dismissed', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    // A DOMException/AbortError from the ceremony — NOT an ApiError.
    loginWithPasskeyAutofill.mockRejectedValue(new Error('The operation was aborted.'))

    const { container, unmount } = render(() => <LoginForm config={config} />)

    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())
    await flush()
    expect(container.querySelector('[role="alert"]')).toBeNull()

    unmount()
  })

  it('passes an abort signal to the autofill request so it can be torn down', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)

    const { unmount } = render(() => <LoginForm config={config} />)

    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())
    expect(loginWithPasskeyAutofill).toHaveBeenCalledWith(expect.any(AbortSignal))

    unmount()
  })

  it('tears down the autofill request when switching to the 2FA code step', async () => {
    passkeyAutofillSupported.mockResolvedValue(true)
    // Capture the signal handed to the autofill request so we can assert it aborts.
    let signal: AbortSignal | undefined
    loginWithPasskeyAutofill.mockImplementation((...args: unknown[]) => {
      signal = args[0] as AbortSignal | undefined

      return new Promise<string>(() => {}) // never resolves — a live conditional ceremony
    })
    const fetchMock = mockFetchOnce(200, { twoFactorRequired: true })

    const { container, getByRole, unmount } = render(() => <LoginForm config={config} />)
    await waitFor(() => expect(loginWithPasskeyAutofill).toHaveBeenCalled())
    fireEvent.input(container.querySelector('input[name="_username"]')!, { target: { value: 'jane' } })
    fireEvent.input(container.querySelector('input[name="_password"]')!, { target: { value: 'pw' } })
    fireEvent.click(getByRole('button', { name: 'Sign in' }))

    // Password accepted → 2FA phase → both teardown paths fire.
    await waitFor(() => expect(getByRole('button', { name: 'Verify' })).toBeInTheDocument())
    expect(cancelPasskeyCeremony).toHaveBeenCalled()
    expect(signal?.aborted).toBe(true)
    expect(fetchMock).toHaveBeenCalled()

    unmount()
  })
  it('renders the firewall field names and the #form-submit button', () => {
    const { container, getByRole, unmount } = render(() => <LoginForm config={config} />)

    expect(container.querySelector('input[name="_username"]')).toBeInTheDocument()
    expect(container.querySelector('input[name="_password"]')).toBeInTheDocument()
    expect(container.querySelector('input[name="_csrf_token"]')).toHaveValue('csrf-123')
    expect(container.querySelector('input[name="_remember_me"]')).toBeChecked()
    expect(getByRole('button', { name: 'Sign in' })).toHaveAttribute('id', 'form-submit')

    unmount()
  })

  it('shows a required-field error and does not call fetch when empty', async () => {
    const fetchMock = mockFetchOnce(200, { ok: true })
    const { getByRole, findByRole, unmount } = render(() => <LoginForm config={config} />)

    fireEvent.submit(getByRole('button', { name: 'Sign in' }).closest('form') as HTMLFormElement)

    expect(await findByRole('alert')).toHaveTextContent('Please enter your username and password.')
    expect(fetchMock).not.toHaveBeenCalled()

    unmount()
  })

  it('posts credentials and redirects on success', async () => {
    const fetchMock = mockFetchOnce(200, { ok: true, redirect: '/' })
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    const { container, getByRole, unmount } = render(() => <LoginForm config={config} />)

    fireEvent.input(container.querySelector('input[name="_username"]')!, { target: { value: 'developer' } })
    fireEvent.input(container.querySelector('input[name="_password"]')!, { target: { value: 'secret' } })
    fireEvent.click(getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'))
    expect(fetchMock).toHaveBeenCalledWith(
      '/login',
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-Requested-With': 'XMLHttpRequest' }) }),
    )
    const body = (fetchMock.mock.calls[0]![1] as { body: URLSearchParams }).body
    expect(body.get('_username')).toBe('developer')
    expect(body.get('_csrf_token')).toBe('csrf-123')
    expect(body.get('_remember_me')).toBe('on')

    unmount()
  })

  it('shows an inline error on failed authentication', async () => {
    mockFetchOnce(401, { ok: false, error: 'Invalid credentials.' })
    const { container, getByRole, findByRole, unmount } = render(() => <LoginForm config={config} />)

    fireEvent.input(container.querySelector('input[name="_username"]')!, { target: { value: 'developer' } })
    fireEvent.input(container.querySelector('input[name="_password"]')!, { target: { value: 'wrong' } })
    fireEvent.click(getByRole('button', { name: 'Sign in' }))

    expect(await findByRole('alert')).toHaveTextContent('Login failed')

    unmount()
  })

  it('swaps to the code step when the server signals twoFactorRequired', async () => {
    // 1st fetch: password accepted but 2FA outstanding; 2nd: the code check.
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 401, json: () => Promise.resolve({ ok: false, twoFactorRequired: true }) })
      .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, redirect: '/' }) })
    vi.stubGlobal('fetch', fetchMock)
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    const { container, getByRole, findByLabelText, unmount } = render(() => <LoginForm config={config} />)

    fireEvent.input(container.querySelector('input[name="_username"]')!, { target: { value: 'developer' } })
    fireEvent.input(container.querySelector('input[name="_password"]')!, { target: { value: 'secret' } })
    fireEvent.click(getByRole('button', { name: 'Sign in' }))

    // The form swaps to the one-field challenge without a redirect.
    const codeInput = await findByLabelText('Verification code')
    expect(assign).not.toHaveBeenCalled()

    fireEvent.input(codeInput, { target: { value: '123456' } })
    fireEvent.click(getByRole('button', { name: 'Verify' }))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'))
    expect(fetchMock).toHaveBeenLastCalledWith('/2fa_check', expect.objectContaining({ method: 'POST' }))
    const body = (fetchMock.mock.calls[1]![1] as { body: URLSearchParams }).body
    expect(body.get('_auth_code')).toBe('123456')

    unmount()
  })

  it('shows the 2FA error and stays on the code step for a wrong code', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 401, json: () => Promise.resolve({ ok: false, twoFactorRequired: true }) })
      .mockResolvedValueOnce({ ok: false, status: 401, json: () => Promise.resolve({ ok: false, error: 'invalid' }) })
    vi.stubGlobal('fetch', fetchMock)
    const { container, getByRole, findByLabelText, findByRole, unmount } = render(() => <LoginForm config={config} />)

    fireEvent.input(container.querySelector('input[name="_username"]')!, { target: { value: 'developer' } })
    fireEvent.input(container.querySelector('input[name="_password"]')!, { target: { value: 'secret' } })
    fireEvent.click(getByRole('button', { name: 'Sign in' }))

    const codeInput = await findByLabelText('Verification code')
    fireEvent.input(codeInput, { target: { value: '000000' } })
    fireEvent.click(getByRole('button', { name: 'Verify' }))

    expect(await findByRole('alert')).toHaveTextContent('That code is not valid')
    expect(container.querySelector('input[name="_auth_code"]')).toBeInTheDocument()

    unmount()
  })

  it('returns to the credentials step via the back link', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: false, status: 401, json: () => Promise.resolve({ ok: false, twoFactorRequired: true }) })
    vi.stubGlobal('fetch', fetchMock)
    const { container, getByRole, findByLabelText, unmount } = render(() => <LoginForm config={config} />)

    fireEvent.input(container.querySelector('input[name="_username"]')!, { target: { value: 'developer' } })
    fireEvent.input(container.querySelector('input[name="_password"]')!, { target: { value: 'secret' } })
    fireEvent.click(getByRole('button', { name: 'Sign in' }))
    await findByLabelText('Verification code')

    fireEvent.click(getByRole('button', { name: 'Back to sign-in' }))

    expect(container.querySelector('input[name="_username"]')).toBeInTheDocument()

    unmount()
  })

  it('has no automatically detectable accessibility violations', async () => {
    const { container, unmount } = render(() => <LoginForm config={config} />)

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })
})
