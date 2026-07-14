import { afterEach, describe, expect, it, vi } from 'vitest'

const startAuthentication = vi.fn()
const startRegistration = vi.fn()
const cancelCeremony = vi.fn()
const getJson = vi.fn()
const postJson = vi.fn()

// Mock the browser WebAuthn primitives and the API client so the real
// requestPasskeyLogin body runs — this is exactly the boundary LoginForm's
// tests mock away, so the endpoint sequence + mediation branching is proven here.
vi.mock('@simplewebauthn/browser', () => ({
  browserSupportsWebAuthn: () => true,
  browserSupportsWebAuthnAutofill: () => Promise.resolve(true),
  startAuthentication: (...args: unknown[]) => startAuthentication(...args),
  startRegistration: (...args: unknown[]) => startRegistration(...args),
  WebAuthnAbortService: { cancelCeremony: () => cancelCeremony() },
}))
vi.mock('../api/client', () => ({
  getJson: (...args: unknown[]) => getJson(...args),
  postJson: (...args: unknown[]) => postJson(...args),
}))

const { loginWithPasskey, loginWithPasskeyAutofill, cancelPasskeyCeremony } = await import('./passkeys')

describe('passkeys login', () => {
  afterEach(() => vi.clearAllMocks())

  it('autofill login uses conditional mediation and posts options then the assertion', async () => {
    postJson
      .mockResolvedValueOnce({ challenge: 'c' })                 // /login/options
      .mockResolvedValueOnce({ ok: true, redirect: '/ui/tracking' }) // /login/passkey
    startAuthentication.mockResolvedValueOnce({ id: 'assertion' })

    const redirect = await loginWithPasskeyAutofill(() => false)

    expect(postJson).toHaveBeenNthCalledWith(1, '/login/options', {})
    expect(startAuthentication).toHaveBeenCalledWith(expect.objectContaining({ useBrowserAutofill: true }))
    expect(postJson).toHaveBeenNthCalledWith(2, '/login/passkey', { id: 'assertion' })
    expect(redirect).toBe('/ui/tracking')
  })

  it('explicit login uses modal mediation (no autofill)', async () => {
    postJson.mockResolvedValueOnce({}).mockResolvedValueOnce({ redirect: '/' })
    startAuthentication.mockResolvedValueOnce({ id: 'x' })

    await loginWithPasskey(() => false)

    expect(startAuthentication).toHaveBeenCalledWith(expect.objectContaining({ useBrowserAutofill: false }))
  })

  it('appends _remember_me=1 to the result URL when "Stay logged in" is on (#587)', async () => {
    // The assertion body is raw JSON, which the backend's remember-me listener
    // does not read — the query parameter is the only opt-in channel.
    postJson.mockResolvedValueOnce({ challenge: 'c' }).mockResolvedValueOnce({ ok: true })
    startAuthentication.mockResolvedValueOnce({ id: 'assertion' })

    await loginWithPasskey(() => true)

    expect(postJson).toHaveBeenNthCalledWith(2, '/login/passkey?_remember_me=1', { id: 'assertion' })
  })

  it('reads the remember choice at submit time, not at ceremony start', async () => {
    // The conditional-UI ceremony can sit open for minutes — the checkbox
    // state when the user PICKS a passkey must win.
    let remember = false
    postJson.mockResolvedValueOnce({ challenge: 'c' }).mockResolvedValueOnce({ ok: true })
    startAuthentication.mockImplementationOnce(() => {
      remember = true

      return Promise.resolve({ id: 'assertion' })
    })

    await loginWithPasskeyAutofill(() => remember)

    expect(postJson).toHaveBeenNthCalledWith(2, '/login/passkey?_remember_me=1', { id: 'assertion' })
  })

  it('falls back to / when the server sends no redirect', async () => {
    postJson.mockResolvedValueOnce({}).mockResolvedValueOnce({ ok: true })
    startAuthentication.mockResolvedValueOnce({ id: 'x' })

    expect(await loginWithPasskeyAutofill(() => false)).toBe('/')
  })

  it('cancelPasskeyCeremony aborts the in-flight ceremony', () => {
    cancelPasskeyCeremony()

    expect(cancelCeremony).toHaveBeenCalledTimes(1)
  })

  it('rejects without any request when the signal is already aborted', async () => {
    const controller = new AbortController()
    controller.abort()

    await expect(loginWithPasskeyAutofill(() => false, controller.signal)).rejects.toThrow()
    expect(postJson).not.toHaveBeenCalled()
    expect(startAuthentication).not.toHaveBeenCalled()
  })

  it('rejects after fetching options — before starting the ceremony — when the signal fires mid-flight', async () => {
    const controller = new AbortController()
    // The options fetch resolves, but the caller aborted while it was in flight.
    postJson.mockImplementationOnce(() => {
      controller.abort()

      return Promise.resolve({ challenge: 'c' })
    })

    await expect(loginWithPasskeyAutofill(() => false, controller.signal)).rejects.toThrow()
    expect(postJson).toHaveBeenCalledTimes(1) // options only; the assertion POST never runs
    expect(startAuthentication).not.toHaveBeenCalled()
  })
})
