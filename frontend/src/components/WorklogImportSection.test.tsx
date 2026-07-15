import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import type { SyncRun } from '../api/worklogSync'

const createSyncRun = vi.fn()
const getJson = vi.fn()
const updateWorktime = vi.fn()

// A real import creates entries, so the component refreshes the header
// worktime totals (#620) — spy on that imperative bridge.
vi.mock('../header', () => ({
  updateWorktime: (...args: unknown[]) => updateWorktime(...args),
}))

vi.mock('../api/worklogSync', () => ({
  createSyncRun: (...args: unknown[]) => createSyncRun(...args),
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

const { WorklogImportSection } = await import('./WorklogImportSection')

// Pick an option in a SearchableSelect (single) combobox: open its trigger by the
// field label, then choose the seeded option once the popup mounts it.
async function pickOption(comboboxLabel: string, optionName: string): Promise<void> {
  fireEvent.click(screen.getByRole('button', { name: comboboxLabel }))
  fireEvent.click(await screen.findByRole('option', { name: optionName }))
}

function makeRun(overrides: Partial<SyncRun> = {}): SyncRun {
  return {
    id: 1,
    type: 'import',
    status: 'completed',
    ticket_system_id: 1,
    triggered_by: 'unittest',
    scope: {},
    counters: {},
    started_at: '2026-07-10T08:00:00+00:00',
    finished_at: '2026-07-10T08:01:00+00:00',
    ...overrides,
  }
}

function mockRefs(): void {
  getJson.mockImplementation((path: string) => {
    if (path === '/getTicketSystems') {
      return Promise.resolve([{ ticketSystem: { id: 1, name: 'Jira Cloud', active: true } }])
    }
    if (path === '/getActivities') {
      return Promise.resolve([{ activity: { id: 2, name: 'Development' } }])
    }

    return Promise.resolve([])
  })
}

afterEach(() => {
  cleanup()
  createSyncRun.mockReset()
  getJson.mockReset()
  updateWorktime.mockReset()
})

describe('WorklogImportSection', () => {
  it('previews (dry run) then executes the import, scoped to the current user', async () => {
    mockRefs()
    // Dry runs report would_create; a real import reports created.
    createSyncRun.mockImplementation((payload: { dry_run?: boolean }) =>
      Promise.resolve(
        makeRun({ counters: payload.dry_run ? { would_create: 2 } : { created: 2 } }),
      ),
    )
    const { container, queryClient } = renderWithProviders(() => <WorklogImportSection />)
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    // Exact group name: the "Help: …" trigger inside the legend is kept out of
    // the accessible name by the fieldset's aria-labelledby.
    expect(screen.getByRole('group', { name: 'Import Jira worklogs' })).toBeInTheDocument()

    // Pick the ticket system and default activity in their searchable comboboxes.
    await pickOption('Ticket system', 'Jira Cloud')
    await pickOption('Default activity', 'Development')

    // Step 1: preview → dry_run:true, scoped to the current user (unittest).
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(createSyncRun).toHaveBeenCalledTimes(1))
    expect(createSyncRun).toHaveBeenLastCalledWith(
      expect.objectContaining({
        type: 'import',
        ticket_system_id: 1,
        default_activity_id: 2,
        users: ['unittest'],
        dry_run: true,
      }),
    )
    // The dry-run summary + "nothing imported yet" note appear.
    await waitFor(() => expect(screen.getByText('Would create')).toBeInTheDocument())
    expect(screen.getByText('Preview only — nothing has been imported yet.')).toBeInTheDocument()
    // A dry run writes nothing — the worklog/header must not refresh yet (#620).
    expect(updateWorktime).not.toHaveBeenCalled()

    // Step 2: execute → dry_run:false, success status, conflicts invalidated.
    fireEvent.click(screen.getByRole('button', { name: 'Execute import' }))
    await waitFor(() => expect(createSyncRun).toHaveBeenCalledTimes(2))
    expect(createSyncRun).toHaveBeenLastCalledWith(
      expect.objectContaining({ type: 'import', ticket_system_id: 1, dry_run: false }),
    )
    // Each SearchableSelect carries its own aria-live status region, so scope the
    // success assertion to the import form's success status paragraph.
    await waitFor(() => expect(container.querySelector('.form-status.is-ok')).toHaveTextContent('Import complete.'))
    expect(screen.getByText('Created')).toBeInTheDocument()
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['worklog-sync', 'conflicts'] })
    // The real import created entries: the worklog grid and the header
    // day/week/month totals refresh too (#620).
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['tracking-entries'] })
    expect(updateWorktime).toHaveBeenCalledTimes(1)

    expect(await axe(container)).toHaveNoViolations()
  })

  it('surfaces a failure as an alert and does not announce success', async () => {
    mockRefs()
    createSyncRun.mockRejectedValue(new Error('Jira unreachable'))
    const { container } = renderWithProviders(() => <WorklogImportSection />)

    await pickOption('Ticket system', 'Jira Cloud')
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Jira unreachable'))
    // No success status paragraph on failure (SearchableSelects keep their own
    // aria-live status regions, so target the success class rather than role).
    expect(container.querySelector('.form-status.is-ok')).not.toBeInTheDocument()

    expect(await axe(container)).toHaveNoViolations()
  })

  it('disables the preview until a ticket system is chosen', async () => {
    mockRefs()
    renderWithProviders(() => <WorklogImportSection />)

    // Preview stays disabled until a ticket system is chosen.
    expect(screen.getByRole('button', { name: 'Preview' })).toBeDisabled()

    await pickOption('Ticket system', 'Jira Cloud')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Preview' })).toBeEnabled())
  })
})
