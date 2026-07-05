import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiToken, ApiTokenList, CreatedApiToken } from '../lib/apiTokens'

const listApiTokens = vi.fn()
const createApiToken = vi.fn()
const revokeApiToken = vi.fn()

vi.mock('../api/client', () => ({
  apiErrorMessage: (error: unknown, fallback: string) =>
    error instanceof Error && error.message ? error.message : fallback,
}))

vi.mock('../lib/apiTokens', () => ({
  listApiTokens: (...a: unknown[]) => listApiTokens(...a),
  createApiToken: (...a: unknown[]) => createApiToken(...a),
  revokeApiToken: (...a: unknown[]) => revokeApiToken(...a),
}))

const { ApiTokenControls } = await import('./ApiTokenControls')

const TAXONOMY = { resources: ['entries', 'projects'], actions: ['read', 'write'], wildcard: '*' }

function list(tokens: ApiToken[] = []): ApiTokenList {
  return { tokens, ...TAXONOMY }
}

function created(overrides: Partial<CreatedApiToken> = {}): CreatedApiToken {
  return {
    token: 'tt_pat_secret123',
    id: 1,
    name: 'CI',
    scopes: ['*'],
    createdAt: '2026-07-05T00:00:00+00:00',
    expiresAt: null,
    ...overrides,
  }
}

describe('ApiTokenControls', () => {
  beforeEach(() => {
    listApiTokens.mockReset().mockResolvedValue(list())
    createApiToken.mockReset().mockResolvedValue(created())
    revokeApiToken.mockReset().mockResolvedValue(undefined)
  })
  afterEach(cleanup)

  it('renders the scope grid from the server taxonomy', async () => {
    render(() => <ApiTokenControls />)

    await waitFor(() => expect(screen.getByLabelText('entries:read')).toBeInTheDocument())
    expect(screen.getByLabelText('projects:write')).toBeInTheDocument()
  })

  it('prevents selecting a past expiry date', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    const today = new Date().toISOString().slice(0, 10)
    expect(screen.getByLabelText('Expires', { exact: false })).toHaveAttribute('min', today)
  })

  it('blocks submit and does not call the API when name or scopes are missing', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.click(screen.getByRole('button', { name: 'Create token' }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(createApiToken).not.toHaveBeenCalled()
  })

  it('creates a wildcard token and shows the plaintext once', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.input(screen.getByPlaceholderText('e.g. CI pipeline'), { target: { value: 'CI' } })
    fireEvent.change(screen.getByLabelText('Full access', { exact: false }), { target: { checked: true } })
    fireEvent.click(screen.getByRole('button', { name: 'Create token' }))

    await waitFor(() => expect(createApiToken).toHaveBeenCalledWith({ name: 'CI', scopes: ['*'], expiresAt: undefined }))
    // The one-time secret is revealed with a copy control.
    expect(await screen.findByText('tt_pat_secret123')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument()
  })

  it('creates a token with the selected grid scopes', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.input(screen.getByPlaceholderText('e.g. CI pipeline'), { target: { value: 'reader' } })
    fireEvent.change(screen.getByLabelText('entries:read'), { target: { checked: true } })
    fireEvent.change(screen.getByLabelText('projects:read'), { target: { checked: true } })
    fireEvent.click(screen.getByRole('button', { name: 'Create token' }))

    await waitFor(() =>
      expect(createApiToken).toHaveBeenCalledWith({
        name: 'reader',
        scopes: ['entries:read', 'projects:read'],
        expiresAt: undefined,
      }),
    )
  })

  it('revokes an active token', async () => {
    const token: ApiToken = {
      id: 42,
      name: 'old',
      scopes: ['entries:read'],
      createdAt: '2026-01-01T00:00:00+00:00',
      lastUsedAt: null,
      expiresAt: null,
      revokedAt: null,
    }
    listApiTokens.mockResolvedValue(list([token]))
    render(() => <ApiTokenControls />)

    const revokeButton = await screen.findByRole('button', { name: 'Revoke' })
    fireEvent.click(revokeButton)

    await waitFor(() => expect(revokeApiToken).toHaveBeenCalledWith(42))
  })

  it('shows a badge and no revoke action for a revoked token', async () => {
    const token: ApiToken = {
      id: 7,
      name: 'spent',
      scopes: ['*'],
      createdAt: '2026-01-01T00:00:00+00:00',
      lastUsedAt: null,
      expiresAt: null,
      revokedAt: '2026-02-01T00:00:00+00:00',
    }
    listApiTokens.mockResolvedValue(list([token]))
    render(() => <ApiTokenControls />)

    expect(await screen.findByText('Revoked')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Revoke' })).not.toBeInTheDocument()
  })
})
