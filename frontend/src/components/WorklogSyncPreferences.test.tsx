import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import type { WorklogSyncPreference, WorklogSyncPreferences as WorklogSyncPreferencesData } from '../api/worklogSync'

const putWorklogSyncPreferences = vi.fn()
const preferencesQueryFn = vi.fn()

// Mock the API module: the query factory returns a stable key plus a queryFn the
// test controls, and the write helper is a spy. worklogSyncKeys.preferences is
// echoed so the component's invalidate targets the same key the test asserts on.
vi.mock('../api/worklogSync', () => ({
  worklogSyncPreferencesQuery: () => ({
    queryKey: ['worklog-sync', 'preferences'],
    queryFn: () => preferencesQueryFn(),
  }),
  putWorklogSyncPreferences: (...args: unknown[]) => putWorklogSyncPreferences(...args),
  worklogSyncKeys: {
    runs: ['worklog-sync', 'runs'],
    conflicts: ['worklog-sync', 'conflicts'],
    preferences: ['worklog-sync', 'preferences'],
  },
}))

vi.mock('../api/client', () => ({
  apiErrorMessage: (error: unknown, fallback: string) =>
    error instanceof Error && error.message ? error.message : fallback,
}))

const { WorklogSyncPreferences } = await import('./WorklogSyncPreferences')

function makePreference(overrides: Partial<WorklogSyncPreference> = {}): WorklogSyncPreference {
  return {
    ticket_system_id: 1,
    ticket_system_name: 'Jira Cloud',
    sync_enabled: false,
    sync_all: false,
    ...overrides,
  }
}

function mockPrefs(data: WorklogSyncPreferencesData): void {
  preferencesQueryFn.mockResolvedValue(data)
}

afterEach(() => {
  cleanup()
  putWorklogSyncPreferences.mockReset()
  preferencesQueryFn.mockReset()
})

describe('WorklogSyncPreferences', () => {
  it('lists a toggle per ticket system and PUTs when sync_enabled is toggled', async () => {
    mockPrefs({ preferences: [makePreference()], can_sync_all: false })
    putWorklogSyncPreferences.mockResolvedValue(makePreference({ sync_enabled: true }))

    const { container, queryClient } = renderWithProviders(() => <WorklogSyncPreferences />)
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await waitFor(() => expect(screen.getByText('Jira Cloud')).toBeInTheDocument())
    const enableToggle = screen.getByRole('checkbox', { name: 'Sync my Jira worklogs' })
    expect(enableToggle).not.toBeChecked()

    // Opting in → PUT the caller's own row with sync_enabled:true (no sync_all
    // for a plain user), and the preferences cache is invalidated.
    fireEvent.click(enableToggle)
    await waitFor(() => expect(putWorklogSyncPreferences).toHaveBeenCalledTimes(1))
    expect(putWorklogSyncPreferences).toHaveBeenLastCalledWith({
      ticket_system_id: 1,
      sync_enabled: true,
    })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['worklog-sync', 'preferences'] })
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Sync preferences saved.'))

    expect(await axe(container)).toHaveNoViolations()
  })

  it('offers the sync_all toggle to a PO and sends both flags', async () => {
    mockPrefs({ preferences: [makePreference({ sync_enabled: true })], can_sync_all: true })
    putWorklogSyncPreferences.mockResolvedValue(makePreference({ sync_enabled: true, sync_all: true }))

    const { container } = renderWithProviders(() => <WorklogSyncPreferences />)

    await waitFor(() => expect(screen.getByText('Jira Cloud')).toBeInTheDocument())
    const syncAllToggle = screen.getByRole('checkbox', { name: 'Sync all worklogs I can access in Jira' })
    expect(syncAllToggle).not.toBeChecked()

    // Turning on sync_all sends the current sync_enabled alongside it.
    fireEvent.click(syncAllToggle)
    await waitFor(() => expect(putWorklogSyncPreferences).toHaveBeenCalledTimes(1))
    expect(putWorklogSyncPreferences).toHaveBeenLastCalledWith({
      ticket_system_id: 1,
      sync_enabled: true,
      sync_all: true,
    })

    expect(await axe(container)).toHaveNoViolations()
  })

  it('hides the sync_all toggle from a plain user', async () => {
    mockPrefs({ preferences: [makePreference()], can_sync_all: false })

    renderWithProviders(() => <WorklogSyncPreferences />)

    await waitFor(() => expect(screen.getByRole('checkbox', { name: 'Sync my Jira worklogs' })).toBeInTheDocument())
    expect(screen.queryByRole('checkbox', { name: 'Sync all worklogs I can access in Jira' })).not.toBeInTheDocument()
  })

  it('surfaces a failure as an alert and does not announce success', async () => {
    mockPrefs({ preferences: [makePreference()], can_sync_all: false })
    putWorklogSyncPreferences.mockRejectedValue(new Error('Jira unreachable'))

    const { container } = renderWithProviders(() => <WorklogSyncPreferences />)

    await waitFor(() => expect(screen.getByText('Jira Cloud')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sync my Jira worklogs' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Jira unreachable'))
    expect(screen.queryByRole('status')).not.toBeInTheDocument()

    expect(await axe(container)).toHaveNoViolations()
  })

  it('shows an empty state when no ticket systems are connected', async () => {
    mockPrefs({ preferences: [], can_sync_all: false })

    const { container } = renderWithProviders(() => <WorklogSyncPreferences />)

    await waitFor(() => expect(screen.getByText('You have no connected Jira ticket systems.')).toBeInTheDocument())
    expect(await axe(container)).toHaveNoViolations()
  })
})
