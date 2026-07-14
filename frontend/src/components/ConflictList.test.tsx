import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import type { SyncConflict } from '../api/worklogSync'

const resolveConflict = vi.fn()
const conflictsQueryFn = vi.fn()
const updateWorktime = vi.fn()

// A resolve can change local entries, so the component refreshes the header
// worktime totals (#620) — spy on that imperative bridge.
vi.mock('../header', () => ({
  updateWorktime: (...args: unknown[]) => updateWorktime(...args),
}))

// Mock the API module: the query factory returns a stable key plus a queryFn the
// test controls, and the write helper is a spy. worklogSyncKeys is echoed so the
// component's invalidate targets the same key the test asserts on.
vi.mock('../api/worklogSync', () => ({
  syncConflictsQuery: (user?: string) => ({
    queryKey: ['worklog-sync', 'conflicts', user === undefined ? {} : { user }],
    queryFn: () => conflictsQueryFn(),
  }),
  resolveConflict: (...args: unknown[]) => resolveConflict(...args),
  worklogSyncKeys: {
    runs: ['worklog-sync', 'runs'],
    conflicts: ['worklog-sync', 'conflicts'],
  },
}))

const { ConflictList } = await import('./ConflictList')

function makeConflict(overrides: Partial<SyncConflict> = {}): SyncConflict {
  return {
    id: 5,
    status: 'conflict',
    entry: {
      id: 42,
      user: 'jdoe',
      ticket: 'ABC-1',
      day: '2026-07-09',
      start: '09:00',
      end: '10:30',
      duration: 90,
      description: 'Local work',
    },
    base_payload: {},
    base_updated_at: '2026-07-08T10:00:00+00:00',
    conflict_remote: {
      comment: 'Remote work',
      started: '2026-07-09T09:00:00+00:00',
      timeSpentSeconds: 3600,
      updated: '2026-07-09T11:00:00+00:00',
    },
    last_synced_at: '2026-07-08T10:00:00+00:00',
    ...overrides,
  }
}

afterEach(() => {
  cleanup()
  resolveConflict.mockReset()
  conflictsQueryFn.mockReset()
  updateWorktime.mockReset()
})

describe('ConflictList', () => {
  it('renders both sides and resolves in favour of the remote side', async () => {
    // First load has the conflict; after the resolve invalidates, the refetch is empty.
    conflictsQueryFn
      .mockResolvedValueOnce({ conflicts: [makeConflict()], count: 1 })
      .mockResolvedValue({ conflicts: [], count: 0 })
    resolveConflict.mockResolvedValue({ resolved: true, action: 'kept_remote', conflict_id: 5 })

    const { container, queryClient } = renderWithProviders(() => <ConflictList />)
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    // Local side: entry fields.
    await waitFor(() => expect(screen.getByText('Local work')).toBeInTheDocument())
    expect(screen.getByText('ABC-1')).toBeInTheDocument()
    expect(screen.getByText('90')).toBeInTheDocument()
    // Remote side: comment + minutes (3600s → 60min).
    expect(screen.getByText('Remote work')).toBeInTheDocument()
    expect(screen.getByText('60')).toBeInTheDocument()

    // Keep remote → resolveConflict(id, 'remote') + conflicts invalidated.
    fireEvent.click(screen.getByRole('button', { name: 'Keep remote: ABC-1' }))
    await waitFor(() => expect(resolveConflict).toHaveBeenCalledWith(5, 'remote'))
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['worklog-sync', 'conflicts'] })
    // The resolution changed a local entry: the worklog grid and the header
    // day/week/month totals refresh too (#620).
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['tracking-entries'] })
    expect(updateWorktime).toHaveBeenCalled()

    // Success is announced and the resolved row disappears on the refetch.
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Conflict resolved.'))
    await waitFor(() => expect(screen.queryByText('Local work')).not.toBeInTheDocument())

    expect(await axe(container)).toHaveNoViolations()
  })

  it('keeps the local side when Keep local is pressed', async () => {
    conflictsQueryFn.mockResolvedValue({ conflicts: [makeConflict()], count: 1 })
    resolveConflict.mockResolvedValue({ resolved: true, action: 'kept_local', conflict_id: 5 })

    renderWithProviders(() => <ConflictList />)

    await waitFor(() => expect(screen.getByText('Local work')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Keep local: ABC-1' }))
    await waitFor(() => expect(resolveConflict).toHaveBeenCalledWith(5, 'local'))
  })

  it('shows the empty state when there are no conflicts', async () => {
    conflictsQueryFn.mockResolvedValue({ conflicts: [], count: 0 })

    const { container } = renderWithProviders(() => <ConflictList />)

    await waitFor(() => expect(screen.getByText('No conflicts to resolve.')).toBeInTheDocument())
    expect(await axe(container)).toHaveNoViolations()
  })

  it('marks the remote as deleted for an orphaned conflict', async () => {
    conflictsQueryFn.mockResolvedValue({
      conflicts: [makeConflict({ status: 'orphaned', conflict_remote: null })],
      count: 1,
    })

    const { container } = renderWithProviders(() => <ConflictList />)

    await waitFor(() => expect(screen.getByText('Remote worklog deleted')).toBeInTheDocument())
    // Both resolve controls stay available (keeping the remote drops the local entry).
    expect(screen.getByRole('button', { name: 'Keep remote: ABC-1' })).toBeInTheDocument()
    expect(await axe(container)).toHaveNoViolations()
  })

  it('surfaces a resolve failure as an alert', async () => {
    conflictsQueryFn.mockResolvedValue({ conflicts: [makeConflict()], count: 1 })
    resolveConflict.mockRejectedValue(new Error('boom'))

    const { container } = renderWithProviders(() => <ConflictList />)

    await waitFor(() => expect(screen.getByText('Local work')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Keep local: ABC-1' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Could not resolve the conflict.'))
    // Nothing changed server-side — no header-totals refresh.
    expect(updateWorktime).not.toHaveBeenCalled()
    expect(await axe(container)).toHaveNoViolations()
  })

  it('scopes the query to a user when the prop is given', async () => {
    conflictsQueryFn.mockResolvedValue({ conflicts: [], count: 0 })

    renderWithProviders(() => <ConflictList user="jdoe" />)

    await waitFor(() => expect(conflictsQueryFn).toHaveBeenCalled())
  })
})
