import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { SessionExpiredOverlay } from './SessionExpiredOverlay'

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
})
