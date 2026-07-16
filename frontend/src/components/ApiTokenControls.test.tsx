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
    // Exact group name: the "Help: …" trigger inside the legend is kept out of
    // the accessible name by the fieldset's aria-labelledby.
    expect(screen.getByRole('group', { name: 'Scopes' })).toBeInTheDocument()
  })

  it('blocks submit when the expiry date is in the past', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.input(screen.getByPlaceholderText('e.g. CI pipeline'), { target: { value: 'CI' } })
    fireEvent.change(screen.getByLabelText('Full access', { exact: false }), { target: { checked: true } })
    // The enhanced date field accepts ISO; a past date commits into the signal.
    const expiry = screen.getByLabelText('Expires', { exact: false })
    fireEvent.input(expiry, { target: { value: '2020-01-01' } })
    fireEvent.change(expiry)
    fireEvent.click(screen.getByRole('button', { name: 'Create token' }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(createApiToken).not.toHaveBeenCalled()
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

  it('column select-all fills its action column and shows a three-state header', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    const selectAllRead = screen.getByLabelText('Select all read') as HTMLInputElement

    // One of two read cells ticked → the header is partial (indeterminate).
    fireEvent.change(screen.getByLabelText('entries:read'), { target: { checked: true } })
    await waitFor(() => expect(selectAllRead.indeterminate).toBe(true))
    expect(selectAllRead.checked).toBe(false)

    // The header select-all fills the whole read column, no longer partial.
    fireEvent.change(selectAllRead, { target: { checked: true } })
    expect(screen.getByLabelText('entries:read')).toBeChecked()
    expect(screen.getByLabelText('projects:read')).toBeChecked()
    expect(screen.getByLabelText('entries:write')).not.toBeChecked()
    await waitFor(() => expect(selectAllRead.indeterminate).toBe(false))
    expect(selectAllRead.checked).toBe(true)

    // Clearing the header empties the column again.
    fireEvent.change(selectAllRead, { target: { checked: false } })
    expect(screen.getByLabelText('entries:read')).not.toBeChecked()
    expect(screen.getByLabelText('projects:read')).not.toBeChecked()
  })

  it('full-access mode hides the scope grid and shows the wildcard warning', async () => {
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.change(screen.getByLabelText('Full access', { exact: false }), { target: { checked: true } })

    // The grid is gone and the caution note takes its place.
    expect(screen.queryByLabelText('entries:read')).not.toBeInTheDocument()
    expect(screen.getByRole('note')).toBeInTheDocument()
  })

  it('applies a preset as exactly its scope set and marks it active', async () => {
    listApiTokens.mockResolvedValue({ tokens: [], resources: ['entries', 'sync'], actions: ['read', 'write'], wildcard: '*' })
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.click(screen.getByRole('button', { name: 'Jira sync' }))

    // Jira-Sync = sync:read, sync:write, entries:read — exactly those, nothing else.
    expect(screen.getByLabelText('sync:read')).toBeChecked()
    expect(screen.getByLabelText('sync:write')).toBeChecked()
    expect(screen.getByLabelText('entries:read')).toBeChecked()
    expect(screen.getByLabelText('entries:write')).not.toBeChecked()

    // The matching preset is flagged active; a non-matching one is not.
    expect(screen.getByRole('button', { name: 'Jira sync' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: 'Time tracking' })).toHaveAttribute('aria-pressed', 'false')
  })

  it('derives the read-only preset from the server resource list', async () => {
    listApiTokens.mockResolvedValue({
      tokens: [],
      resources: ['entries', 'projects', 'reporting'],
      actions: ['read', 'write'],
      wildcard: '*',
    })
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.click(screen.getByRole('button', { name: 'Read-only' }))

    // Every resource's :read, no :write — derived, not hard-coded.
    expect(screen.getByLabelText('entries:read')).toBeChecked()
    expect(screen.getByLabelText('projects:read')).toBeChecked()
    expect(screen.getByLabelText('reporting:read')).toBeChecked()
    expect(screen.getByLabelText('entries:write')).not.toBeChecked()
    expect(screen.getByLabelText('projects:write')).not.toBeChecked()
    expect(screen.getByLabelText('reporting:write')).not.toBeChecked()
  })

  it('drops preset scopes the server does not advertise', async () => {
    // Taxonomy has only entries: the time-tracking preset also names projects,
    // activities and customers, which must be silently dropped.
    listApiTokens.mockResolvedValue({ tokens: [], resources: ['entries'], actions: ['read', 'write'], wildcard: '*' })
    render(() => <ApiTokenControls />)
    await waitFor(() => screen.getByLabelText('entries:read'))

    fireEvent.input(screen.getByPlaceholderText('e.g. CI pipeline'), { target: { value: 'tt' } })
    fireEvent.click(screen.getByRole('button', { name: 'Time tracking' }))
    fireEvent.click(screen.getByRole('button', { name: 'Create token' }))

    await waitFor(() =>
      expect(createApiToken).toHaveBeenCalledWith({
        name: 'tt',
        scopes: ['entries:read', 'entries:write'],
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
