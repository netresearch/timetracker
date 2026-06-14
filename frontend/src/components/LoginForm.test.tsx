import { fireEvent, render, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import type { LoginConfig } from '../loginConfig'
import { LoginForm } from './LoginForm'

const config: LoginConfig = {
  locale: 'en',
  appTitle: 'TimeTracker',
  logoUrl: '/logo.svg',
  csrfToken: 'csrf-123',
  loginPath: '/login',
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

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('LoginForm', () => {
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

  it('has no automatically detectable accessibility violations', async () => {
    const { container, unmount } = render(() => <LoginForm config={config} />)

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })
})
