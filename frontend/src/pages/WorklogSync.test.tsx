import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import type { SyncRun } from '../api/worklogSync'

const createSyncRun = vi.fn()
const getJson = vi.fn()
const updateWorktime = vi.fn()

// A real (non-dry) run can change entries, so the page refreshes the header
// worktime totals (#620) — spy on that imperative bridge.
vi.mock('../header', () => ({
  updateWorktime: (...args: unknown[]) => updateWorktime(...args),
}))

// syncRunsQuery / syncRunQuery are replaced with factories that resolve seeded
// data; createSyncRun is a spy the trigger form calls.
const RUNS: SyncRun[] = [
  {
    id: 10,
    type: 'import',
    status: 'completed',
    ticket_system_id: 1,
    triggered_by: 'admin',
    scope: {},
    counters: { created: 3 },
    started_at: '2026-07-10T08:00:00+00:00',
    finished_at: '2026-07-10T08:05:00+00:00',
  },
  {
    id: 11,
    type: 'verify',
    status: 'failed',
    ticket_system_id: 1,
    triggered_by: 'admin',
    scope: {},
    counters: { errors: 1 },
    started_at: '2026-07-09T08:00:00+00:00',
    finished_at: null,
  },
]

vi.mock('../api/worklogSync', () => ({
  createSyncRun: (...args: unknown[]) => createSyncRun(...args),
  syncRunsQuery: (ticketSystemId?: number) => ({
    queryKey: ['worklog-sync', 'runs', { ticketSystemId }],
    queryFn: () => Promise.resolve({ runs: RUNS, count: RUNS.length }),
  }),
  syncRunQuery: (id: number) => ({
    queryKey: ['worklog-sync', 'runs', id],
    queryFn: () =>
      Promise.resolve({
        ...RUNS[0]!,
        id,
        items: [
          {
            kind: 'conflict',
            issue_key: 'ABC-1',
            remote_worklog_id: 99,
            entry_id: 42,
            author: 'admin',
            reason: 'both changed',
            payload: null,
            created_at: '2026-07-10T08:00:00+00:00',
          },
        ],
      }),
  }),
  syncConflictsQuery: (user?: string) => ({
    queryKey: ['worklog-sync', 'conflicts', { user }],
    queryFn: () => Promise.resolve({ conflicts: [], count: 0 }),
  }),
  resolveConflict: () => Promise.resolve({ resolved: true, action: 'pulled_remote', conflict_id: 1 }),
  worklogSyncKeys: {
    runs: ['worklog-sync', 'runs'],
    conflicts: ['worklog-sync', 'conflicts'],
  },
}))

vi.mock('../api/client', () => ({
  apiErrorMessage: (error: unknown, fallback: string) =>
    error instanceof Error && error.message ? error.message : fallback,
  getJson: (...args: unknown[]) => getJson(...args),
}))

const { default: WorklogSync } = await import('./WorklogSync')

function makeRun(overrides: Partial<SyncRun> = {}): SyncRun {
  return {
    id: 1,
    type: 'verify',
    status: 'completed',
    ticket_system_id: 1,
    triggered_by: 'admin',
    scope: {},
    counters: {},
    started_at: '2026-07-10T09:00:00+00:00',
    finished_at: '2026-07-10T09:01:00+00:00',
    ...overrides,
  }
}

function mockRefs(): void {
  getJson.mockImplementation((path: string) => {
    if (path === '/getTicketSystems') {
      return Promise.resolve([{ ticketSystem: { id: 1, name: 'Jira Cloud', active: true } }])
    }

    return Promise.resolve([])
  })
}

// Pick a ticket system in the trigger form's searchable combobox: the two
// 'Ticket system' comboboxes (trigger + history filter) share a label, so the
// first (region 1 — trigger) is opened, then the seeded option is chosen.
async function pickTriggerTicketSystem(): Promise<void> {
  fireEvent.click(screen.getAllByRole('button', { name: 'Ticket system' })[0]!)
  fireEvent.click(await screen.findByRole('option', { name: 'Jira Cloud' }))
}

const FULL_ROLES = ['ROLE_USER', 'ROLE_PL', 'ROLE_ADMIN']

afterEach(() => {
  cleanup()
  createSyncRun.mockReset()
  getJson.mockReset()
  updateWorktime.mockReset()
  window.APP_CONFIG!.roles = [...FULL_ROLES]
})

describe('WorklogSync', () => {
  it('lists recent runs and triggers a new run', async () => {
    mockRefs()
    createSyncRun.mockResolvedValue(makeRun({ status: 'completed', counters: { created: 2 } }))
    const { container, queryClient } = renderWithProviders(() => <WorklogSync />)
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    // Run history renders the seeded runs (both status badges appear).
    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
    expect(screen.getByText('Completed')).toBeInTheDocument()
    expect(screen.getByText('Failed')).toBeInTheDocument()

    // Pick a ticket system in the trigger combobox, then trigger a run.
    await pickTriggerTicketSystem()
    fireEvent.click(screen.getByRole('button', { name: 'Trigger a run' }))

    await waitFor(() => expect(createSyncRun).toHaveBeenCalledTimes(1))
    expect(createSyncRun).toHaveBeenLastCalledWith(
      expect.objectContaining({ type: 'verify', ticket_system_id: 1, dry_run: true }),
    )
    // The returned run's summary + a success status appear; runs are invalidated.
    // (Each SearchableSelect carries its own aria-live status region, so scope to
    // the trigger form's success status paragraph rather than role='status'.)
    await waitFor(() => expect(screen.getByText('Created')).toBeInTheDocument())
    expect(container.querySelector('.form-status.is-ok')).toBeInTheDocument()
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['worklog-sync', 'runs'] })
    // The default trigger is a dry run — it writes nothing, so the worklog grid
    // and the header totals must not refresh (#620).
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ['tracking-entries'] })
    expect(updateWorktime).not.toHaveBeenCalled()

    expect(await axe(container)).toHaveNoViolations()
  })

  it('refreshes the worklog and header totals after a real (non-dry) run', async () => {
    mockRefs()
    createSyncRun.mockResolvedValue(makeRun({ type: 'import', counters: { created: 1 } }))
    const { queryClient } = renderWithProviders(() => <WorklogSync />)
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await pickTriggerTicketSystem()
    fireEvent.click(screen.getByLabelText('Dry run')) // uncheck → a real run
    fireEvent.click(screen.getByRole('button', { name: 'Trigger a run' }))

    await waitFor(() => expect(createSyncRun).toHaveBeenCalledTimes(1))
    expect(createSyncRun).toHaveBeenLastCalledWith(expect.objectContaining({ dry_run: false }))
    // A real run can create/change entries: the worklog grid and the header
    // day/week/month totals refresh (#620).
    await waitFor(() => expect(updateWorktime).toHaveBeenCalledTimes(1))
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['tracking-entries'] })
  })

  it('maps a picked user id back to its username in the trigger payload', async () => {
    // The users picker is a relation combobox over ids, but the API targets users
    // by username — the payload must still carry the name the old <select> sent.
    getJson.mockImplementation((path: string) => {
      if (path === '/getTicketSystems') {
        return Promise.resolve([{ ticketSystem: { id: 1, name: 'Jira Cloud', active: true } }])
      }
      if (path === '/getAllUsers') {
        return Promise.resolve([{ user: { id: 7, username: 'alice' } }])
      }

      return Promise.resolve([])
    })
    createSyncRun.mockResolvedValue(makeRun())
    renderWithProviders(() => <WorklogSync />)

    await pickTriggerTicketSystem()
    // Open the users multi-combobox (▾), filter, pick alice. Ark applies the
    // selection asynchronously, so wait for its chip before triggering.
    fireEvent.click(screen.getByRole('button', { name: 'Users' }))
    fireEvent.input(screen.getByRole('combobox', { name: 'Users' }), { target: { value: 'ali' } })
    fireEvent.click(await screen.findByRole('option', { name: 'alice' }))
    // Single-select fields (the trigger ticket system) now also show their value as a
    // chip, so several listitems can be present — assert the alice chip is among them.
    await waitFor(() => expect(screen.getAllByRole('listitem').some((li) => li.textContent?.includes('alice'))).toBe(true))
    fireEvent.click(screen.getByRole('button', { name: 'Trigger a run' }))

    await waitFor(() => expect(createSyncRun).toHaveBeenCalledTimes(1))
    expect(createSyncRun).toHaveBeenLastCalledWith(expect.objectContaining({ users: ['alice'] }))
  })

  it('loads a run detail (with items) when a run is opened', async () => {
    mockRefs()
    renderWithProviders(() => <WorklogSync />)

    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
    // Open the first run's detail — its provenance items load.
    fireEvent.click(screen.getAllByRole('button', { name: /Show run details/ })[0]!)
    await waitFor(() => expect(screen.getByText('both changed')).toBeInTheDocument())
    expect(screen.getByText('ABC-1')).toBeInTheDocument()
  })

  it('surfaces a trigger failure as an alert', async () => {
    mockRefs()
    createSyncRun.mockRejectedValue(new Error('Jira unreachable'))
    const { container } = renderWithProviders(() => <WorklogSync />)

    await pickTriggerTicketSystem()
    fireEvent.click(screen.getByRole('button', { name: 'Trigger a run' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Jira unreachable'))
    // No success status paragraph on failure (SearchableSelects keep their own
    // aria-live status regions, so target the trigger form's success class).
    expect(container.querySelector('.form-status.is-ok')).not.toBeInTheDocument()
  })

  it('does not expose the sync area to a non-admin', () => {
    mockRefs()
    window.APP_CONFIG!.roles = ['ROLE_USER']
    renderWithProviders(() => <WorklogSync />)

    expect(screen.queryByText('Trigger a run')).not.toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
