import { cleanup, fireEvent, screen, waitFor, within } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import Tracking from './Tracking'

const getJson = vi.fn()
const postJson = vi.fn()
const postForm = vi.fn()

vi.mock('../api/client', () => ({
  SessionExpiredError: class extends Error {},
  ApiError: class extends Error {},
  ValidationError: class extends Error {},
  apiErrorMessage: (error: unknown, fallback: string) => (error instanceof Error ? error.message : fallback),
  getJson: (...args: unknown[]) => getJson(...args),
  postJson: (...args: unknown[]) => postJson(...args),
  postForm: (...args: unknown[]) => postForm(...args),
}))

// Single source for the grid's GET endpoints; each test supplies only the data
// it cares about (the rest default to empty) — keeps the mocks duplication-free.
interface MockData {
  entries?: unknown[]
  customers?: unknown[]
  projects?: unknown[]
  activities?: unknown[]
  ticketSystems?: unknown[]
}

function mockTracking(data: MockData): void {
  getJson.mockImplementation((path: string) => {
    if (path.startsWith('/getData/days/')) {
      return Promise.resolve(data.entries ?? [])
    }
    switch (path) {
      case '/getCustomers':
        return Promise.resolve(data.customers ?? [])
      case '/getAllProjects':
        return Promise.resolve(data.projects ?? [])
      case '/getActivities':
        return Promise.resolve(data.activities ?? [])
      case '/getTicketSystems':
        return Promise.resolve(data.ticketSystems ?? [])
      default:
        return Promise.resolve([])
    }
  })
}

const DEFAULT_ENTRY = {
  id: 1, date: '16/06/2026', start: '09:00', end: '10:30', user: 3,
  customer: 1, project: 4, activity: 5, description: 'Work', ticket: 'ABC-1',
  duration: '1:30', durationMinutes: 90, class: 8, worklog: null, extTicket: null,
}

// mockApi with a custom entries list (the customer/project/activity seed is shared).
function mockApiWith(entries: unknown[]): void {
  mockTracking({
    entries,
    customers: [{ customer: { id: 1, name: 'ACME' } }],
    projects: [{ project: { id: 4, name: 'Site' } }],
    activities: [{ activity: { id: 5, name: 'Dev' } }],
  })
}

function mockApi(): void {
  mockApiWith([{ entry: DEFAULT_ENTRY }])
}

function renderTracking() {
  return renderWithProviders(() => <Tracking />)
}

beforeEach(() => {
  // Freeze the clock (Date only — leave setTimeout/Interval real so waitFor still
  // polls) to noon on the seeded test date. Several toolbar actions read the wall
  // clock — Prolong sets the entry's end to "now" and aborts when now precedes the
  // entry's start — so without a fixed clock the suite fails whenever it runs before
  // the fixture's start time (e.g. an early-morning CI run). Noon keeps every seeded
  // entry in the past.
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2024-01-15T12:00:00'))
})

afterEach(() => {
  cleanup() // unmount even after a throwing test, so a body-portalled combobox + body-inert can't leak into the next test
  // The day range now persists to localStorage — clear it so a range change in
  // one test doesn't leak into the next (which expects the DEFAULT_DAYS range).
  localStorage.clear()
  getJson.mockReset()
  postJson.mockReset()
  postForm.mockReset()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

// Move the keyboard cursor to the Nth body row (0-based) so the active-row
// (aria-current) toolbar actions target it. gridNav sets aria-current on focus,
// then each ArrowDown advances one row.
function focusBodyRow(container: HTMLElement, index: number): void {
  const firstCell = container.querySelector<HTMLElement>('tbody td[data-row-id]')
  if (firstCell === null) {
    throw new Error('no body cell')
  }
  firstCell.focus()
  // gridNav moves focus to the new cell on each step, so fire on whatever cell
  // currently holds focus (not always the first one).
  for (let i = 0; i < index; i += 1) {
    fireEvent.keyDown(document.activeElement ?? firstCell, { key: 'ArrowDown' })
  }
}

// Open the inline editor on a column's first cell and return its control.
function editCell(container: HTMLElement, colKey: string): HTMLInputElement {
  const cell = container.querySelector<HTMLElement>(`td[data-col-key="${colKey}"]`)
  if (cell === null) {
    throw new Error(`no cell for ${colKey}`)
  }
  cell.focus()
  fireEvent.keyDown(cell, { key: 'Enter' })
  // The editor's input is in the cell (text / multi-select) or body-portalled in the
  // combobox popup (single-select search field lives at the top of the dropdown).
  const control = cell.querySelector<HTMLInputElement>('input, select')
    ?? document.querySelector<HTMLInputElement>('.combobox-input')
  if (control === null) {
    throw new Error(`no editor for ${colKey}`)
  }

  return control
}

describe('Tracking (Worklog grid)', () => {
  it('renders recent entries with resolved relation names and the server duration', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()

    // The customer cell resolves the id (1) to its name once /getCustomers loads.
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())
    expect(getByRole('gridcell', { name: 'Site' })).toBeInTheDocument()
    expect(getByRole('gridcell', { name: 'Dev' })).toBeInTheDocument()
    expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument()
    expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument()
    // duration is server-derived, shown verbatim.
    expect(getByRole('gridcell', { name: '1:30' })).toBeInTheDocument()

    unmount()
  })

  it('styles a row from the server-computed class (overlap)', async () => {
    mockApi()
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    expect(container.querySelector('tr.tracking-row.is-overlap')).not.toBeNull()

    unmount()
  })

  it('refetches via /getData/days/{N} when the range changes', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    fireEvent.change(getByRole('combobox'), { target: { value: '7' } })

    await waitFor(() => expect(getJson).toHaveBeenCalledWith('/getData/days/7'))

    unmount()
  })

  it('accepts a freetext (non-preset) day range and refetches it', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    fireEvent.change(getByRole('combobox'), { target: { value: '14' } })

    await waitFor(() => expect(getJson).toHaveBeenCalledWith('/getData/days/14'))

    unmount()
  })

  it('clamps an out-of-range day entry to a year and re-syncs the field', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    const combo = getByRole('combobox') as HTMLInputElement
    fireEvent.change(combo, { target: { value: '9999' } })

    await waitFor(() => expect(getJson).toHaveBeenCalledWith('/getData/days/366'))
    expect(combo.value).toBe('366')

    unmount()
  })

  it('has no automatically detectable accessibility violations', async () => {
    mockApi()
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })

  it('auto-saves the whole entry to /tracking/save on commit when the row is complete', async () => {
    mockApi() // DEFAULT_ENTRY has every required field → editing it keeps it complete
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    const editor = editCell(container, 'ticket')
    fireEvent.input(editor, { target: { value: 'xyz-9' } })
    fireEvent.keyDown(editor, { key: 'Enter' }) // commit → row complete → auto-save

    // Saved on commit — no need to leave the row.
    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({
        id: 1, ticket: 'XYZ-9', date: '2026-06-16', start: '09:00', end: '10:30', customer: 1, project: 4, activity: 5,
      })),
    )

    unmount()
  })

  it('parses a terse start time to H:i before saving', async () => {
    mockApi()
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    const editor = editCell(container, 'start')
    fireEvent.input(editor, { target: { value: '8' } })
    fireEvent.keyDown(editor, { key: 'Enter' })
    ;(getByRole('combobox') as HTMLElement).focus()

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({ start: '08:00' })))

    unmount()
  })

  it('blocks a save with start >= end and shows a localized message, no round-trip (#441)', async () => {
    mockApi()
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    // DEFAULT_ENTRY is 09:00–10:30; set end == start (09:00) and commit.
    const editor = editCell(container, 'end')
    fireEvent.input(editor, { target: { value: '09:00' } })
    fireEvent.keyDown(editor, { key: 'Enter' })

    // Localized validation message shown — and crucially no /tracking/save round-
    // trip (the old behaviour surfaced the backend's untranslated 422 instead).
    await waitFor(() => expect(screen.getByText('Start time must be before end time.')).toBeInTheDocument())
    expect(postJson).not.toHaveBeenCalledWith('/tracking/save', expect.anything())

    unmount()
  })

  it('refreshes the header day/week/month totals after a save (#446)', async () => {
    mockApi()
    // A complete save response so the post-save upsert succeeds and the flow
    // reaches the header refresh (an empty {} would throw in savedEntryToRow).
    postJson.mockResolvedValue({
      result: {
        id: 1, date: '2026-06-16', start: '09:00', end: '10:30', user: 1,
        customer: 1, project: 4, activity: 5, duration: '1:30', durationMinutes: 90, class: 0, ticket: 'XYZ-9',
      },
    })
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    const editor = editCell(container, 'ticket')
    fireEvent.input(editor, { target: { value: 'xyz-9' } })
    fireEvent.keyDown(editor, { key: 'Enter' })

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.anything()))
    // The save also re-pulls the server header's totals (otherwise stale until F5).
    await waitFor(() => expect(getJson).toHaveBeenCalledWith('/getTimeSummary'))

    unmount()
  })

  it('deletes an entry via /tracking/delete after confirmation', async () => {
    mockApi()
    postJson.mockResolvedValue({ success: true })
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    // The trash icon opens an in-page confirmation dialog (no native window.confirm);
    // confirming there triggers the actual delete.
    fireEvent.click(getByRole('button', { name: 'Delete' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))

    // /tracking/delete is form-encoded (reads $request->request), so it goes
    // out via postForm — not postJson.
    await waitFor(() => expect(postForm).toHaveBeenCalledWith('/tracking/delete', { id: 1 }))

    unmount()
  })

  it('maps a ticket prefix to its project and customer on commit', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, customer: 0, project: 0, ticket: '', class: 0 } }],
      customers: [{ customer: { id: 7, name: 'ACME' } }],
      projects: [{ project: { id: 9, name: 'Apollo', customer: 7, jiraId: 'APO' } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    const editor = editCell(container, 'ticket')
    fireEvent.input(editor, { target: { value: 'apo-42' } })
    fireEvent.keyDown(editor, { key: 'Enter' })
    ;(getByRole('combobox') as HTMLElement).focus()

    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({ ticket: 'APO-42', project: 9, customer: 7 })),
    )

    unmount()
  })

  it('derives the project from a ticket whose prefix is one of several in jiraId (#453)', async () => {
    // jiraId is a comma-separated prefix list; an external ticket like DHLSUP-…
    // must still resolve the project (an exact jiraId === prefix match missed it,
    // so the wrong/no project was sent and the save was rejected as invalid).
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, customer: 0, project: 0, ticket: '', class: 0 } }],
      customers: [{ customer: { id: 7, name: 'DHL' } }],
      projects: [{ project: { id: 9, name: 'DHL Support', customer: 7, jiraId: 'SA, DHLSUP' } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    const editor = editCell(container, 'ticket')
    fireEvent.input(editor, { target: { value: 'dhlsup-1067002' } })
    fireEvent.keyDown(editor, { key: 'Enter' })
    ;(getByRole('combobox') as HTMLElement).focus()

    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({ ticket: 'DHLSUP-1067002', project: 9, customer: 7 })),
    )

    unmount()
  })

  it('shows the external ticket the backend mirrored, in its own column (#453)', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, id: 1, ticket: 'OPSDHL-2881', extTicket: 'DHLSUP-1067002', customer: 1, project: 4 } }],
    })
    const { getByRole, unmount } = renderTracking()

    await waitFor(() => expect(getByRole('gridcell', { name: 'DHLSUP-1067002' })).toBeInTheDocument())

    unmount()
  })

  it('hides the Ext. Ticket column entirely when no loaded row has one (#10)', async () => {
    // Both empty shapes (null and '') count as "no value".
    mockApiWith([
      { entry: DEFAULT_ENTRY }, // extTicket: null
      { entry: { ...DEFAULT_ENTRY, id: 2, start: '11:00', end: '12:00', description: 'Other', extTicket: '' } },
    ])
    const { container, getByRole, queryByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Other' })).toBeInTheDocument())

    expect(queryByRole('columnheader', { name: 'Ext. Ticket' })).toBeNull()
    expect(container.querySelector('[data-col-key="extTicket"]')).toBeNull()
    // The column is dropped from the DOM (not display:none-hidden), so the
    // grid's navigable cell count shrinks with it: 9 data columns + Actions.
    expect(getByRole('grid').getAttribute('aria-colcount')).toBe('10')

    unmount()
  })

  it('keeps the Ext. Ticket column when any loaded row has a value (#10)', async () => {
    mockApiWith([
      { entry: DEFAULT_ENTRY }, // extTicket: null — one empty row must not hide the column
      { entry: { ...DEFAULT_ENTRY, id: 2, start: '11:00', end: '12:00', extTicket: 'DHLSUP-1' } },
    ])
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'DHLSUP-1' })).toBeInTheDocument())

    expect(getByRole('columnheader', { name: 'Ext. Ticket' })).toBeInTheDocument()
    // Every row renders the cell (blank where empty) so column indices stay
    // aligned across rows: 10 data columns + Actions.
    expect(container.querySelectorAll('td[data-col-key="extTicket"]')).toHaveLength(2)
    expect(getByRole('grid').getAttribute('aria-colcount')).toBe('11')

    unmount()
  })

  it('filters the project dropdown by the row customer (cascade)', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, customer: 7, project: 9, ticket: '', class: 0 } }],
      customers: [{ customer: { id: 7, name: 'ACME' } }],
      projects: [
        { project: { id: 9, name: 'Apollo', customer: 7, jiraId: 'APO' } },
        { project: { id: 10, name: 'Zeus', customer: 8, jiraId: 'ZEU' } },
      ],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    editCell(container, 'project') // opens the combobox (options portal to body)
    // The cascade filters the combobox to the row customer's projects.
    expect(await screen.findByRole('option', { name: 'Apollo' })).toBeInTheDocument() // customer 7's project
    expect(screen.queryByRole('option', { name: 'Zeus' })).not.toBeInTheDocument() // customer 8's is filtered out

    unmount()
  })

  it('Add inserts a new row that saves as a create (no id)', async () => {
    mockApi()
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Add entry' }))
    // The new row is at the top; fill start + end (both required to save).
    let cell = editCell(container, 'start')
    fireEvent.input(cell, { target: { value: '9' } })
    fireEvent.keyDown(cell, { key: 'Enter' })
    cell = editCell(container, 'end')
    fireEvent.input(cell, { target: { value: '10' } })
    fireEvent.keyDown(cell, { key: 'Enter' })
    ;(getByRole('combobox') as HTMLElement).focus()

    await waitFor(() => {
      const call = postJson.mock.calls.find((args) => args[0] === '/tracking/save')
      expect(call).toBeTruthy()
      expect(call?.[1]).not.toHaveProperty('id') // new entry → server creates
      expect(call?.[1]).toMatchObject({ start: '09:00', end: '10:00' })
    })

    unmount()
  })

  it('Continue clones the latest entry into a new row', async () => {
    mockApi() // the one entry has customer 1 (ACME)
    const { getByRole, getAllByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Continue' }))

    // Original entry + the cloned new row both show the customer 'ACME'.
    await waitFor(() => expect(getAllByRole('gridcell', { name: 'ACME' }).length).toBe(2))

    unmount()
  })

  it('Prolong sets the latest entry end to now and saves it', async () => {
    mockApi()
    postJson.mockResolvedValue({})
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Prolong' }))

    await waitFor(() => {
      const call = postJson.mock.calls.find((args) => args[0] === '/tracking/save')
      expect(call).toBeTruthy()
      expect(call?.[1]).toMatchObject({ id: 1, start: '09:00' })
      expect(String((call?.[1] as Record<string, unknown>).end)).toMatch(/^\d{2}:\d{2}$/)
    })

    unmount()
  })

  it('exposes a CSV export link for the current day range', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    expect(getByRole('link', { name: 'Export CSV' })).toHaveAttribute('href', '/export/3')

    unmount()
  })

  it('Alt+P prolongs the latest entry from the keyboard', async () => {
    mockApi()
    postJson.mockResolvedValue({})
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    fireEvent.keyDown(document, { key: 'p', altKey: true })

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({ id: 1 })))

    unmount()
  })

  it('renders the ticket as a link to its ticket system', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, customer: 7, project: 9, ticket: 'APO-42', class: 0 } }],
      customers: [{ customer: { id: 7, name: 'ACME' } }],
      projects: [{ project: { id: 9, name: 'Apollo', customer: 7, jiraId: 'APO', ticket_system: 2 } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
      ticketSystems: [{ ticketSystem: { id: 2, name: 'Jira', ticketUrl: 'https://jira/browse/%s' } }],
    })
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    expect(getByRole('link', { name: 'APO-42' })).toHaveAttribute('href', 'https://jira/browse/APO-42')

    unmount()
  })

  it('Alt+I requests the summary as form-encoded (legacy $request->request) and shows it', async () => {
    mockApi()
    // /getSummary reads form params, so the client uses postForm and returns the
    // JSON *text*; a JSON body would leave the server's id null → all-zero totals.
    postForm.mockResolvedValue(JSON.stringify({
      customer: { scope: 'customer', name: 'ACME', entries: 5, total: 300, own: 120, estimation: 0 },
      project: { scope: 'project', name: 'Site', entries: 3, total: 180, own: 90, estimation: 600 },
      activity: { scope: 'activity', name: 'Dev', entries: 2, total: 120, own: 60, estimation: 0 },
      ticket: { scope: 'ticket', name: 'ABC-1', entries: 1, total: 60, own: 60, estimation: 0 },
    }))
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    fireEvent.keyDown(document, { key: 'i', altKey: true })

    expect(postForm).toHaveBeenCalledWith('/getSummary', { id: 1 })
    expect(postJson).not.toHaveBeenCalledWith('/getSummary', expect.anything())
    // The summary dialog is portalled to document.body → query via screen.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('ACME')).toBeInTheDocument()
    expect(within(dialog).getByText('Site')).toBeInTheDocument()
    expect(within(dialog).getByText('Dev')).toBeInTheDocument()
    // estimation shown for the project scope (600 min → 10:00).
    expect(within(dialog).getByText('10:00')).toBeInTheDocument()

    unmount()
  })

  it('sorts fetched entries newest-first regardless of server order', async () => {
    mockTracking({
      entries: [
        { entry: { ...DEFAULT_ENTRY, id: 1, date: '14/06/2026', start: '09:00', description: 'Oldest', class: 0 } },
        { entry: { ...DEFAULT_ENTRY, id: 2, date: '16/06/2026', start: '10:00', description: 'Newest', class: 0 } },
        { entry: { ...DEFAULT_ENTRY, id: 3, date: '15/06/2026', start: '14:00', description: 'Middle', class: 0 } },
      ],
      customers: [{ customer: { id: 1, name: 'ACME' } }],
      projects: [{ project: { id: 4, name: 'Site' } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Newest' })).toBeInTheDocument())

    // Dates render in ISO (yyyy-mm-dd), one consistent format everywhere.
    // The date cell renders three responsive widths (full / MM-DD / DD); read the
    // full-date span rather than the whole cell's concatenated text.
    const dates = Array.from(container.querySelectorAll('tbody tr td[data-col-key="date"] .dt-full')).map((el) => el.textContent)
    expect(dates).toEqual(['2026-06-16', '2026-06-15', '2026-06-14'])

    unmount()
  })

  it('Continue clones the keyboard-cursor row (incl. ticket), not the top row', async () => {
    mockTracking({
      entries: [
        { entry: { ...DEFAULT_ENTRY, id: 1, date: '16/06/2026', ticket: 'ABC-1', customer: 1, project: 4 } },
        { entry: { ...DEFAULT_ENTRY, id: 2, date: '15/06/2026', ticket: 'XYZ-9', customer: 2, project: 5 } },
      ],
      customers: [{ customer: { id: 1, name: 'ACME' } }, { customer: { id: 2, name: 'BroCorp' } }],
      projects: [{ project: { id: 4, name: 'Site', customer: 1 } }, { project: { id: 5, name: 'App', customer: 2 } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    const { container, getByRole, getAllByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'BroCorp' })).toBeInTheDocument())

    // Cursor on the 2nd (older, XYZ-9/BroCorp) row, then Continue via Alt+C
    // (the cursor-row shortcut; the per-row icons clone their own row instead).
    focusBodyRow(container, 1)
    fireEvent.keyDown(document.body, { key: 'c', altKey: true })

    // The cloned new row carries the cursor row's customer + ticket (not ABC-1/ACME).
    await waitFor(() => expect(getAllByRole('gridcell', { name: 'BroCorp' }).length).toBe(2))
    const xyzLinks = Array.from(container.querySelectorAll('a.ticket-link')).filter((a) => a.textContent === 'XYZ-9')
    expect(xyzLinks.length).toBe(2)

    unmount()
  })

  it('Alt+I summarizes the keyboard-cursor row, not the top row', async () => {
    mockTracking({
      entries: [
        { entry: { ...DEFAULT_ENTRY, id: 1, date: '16/06/2026', description: 'Top' } },
        { entry: { ...DEFAULT_ENTRY, id: 2, date: '15/06/2026', description: 'Second' } },
      ],
      customers: [{ customer: { id: 1, name: 'ACME' } }],
      projects: [{ project: { id: 4, name: 'Site' } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    postForm.mockResolvedValue(JSON.stringify({
      customer: { scope: 'customer', name: 'ACME', entries: 1, total: 60, own: 60, estimation: 0 },
    }))
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Second' })).toBeInTheDocument())

    focusBodyRow(container, 1) // cursor on the 2nd row (id 2)
    fireEvent.keyDown(document, { key: 'i', altKey: true })

    expect(postForm).toHaveBeenCalledWith('/getSummary', { id: 2 })

    unmount()
  })

  it('normalizes a terse start time in the cell on commit (1300 → 13:00)', async () => {
    // An incomplete row (no customer) won't auto-save, so the normalized draft
    // value stays visible instead of being reverted by a save+refetch.
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, customer: 0, class: 0 } }],
      customers: [{ customer: { id: 1, name: 'ACME' } }],
      projects: [{ project: { id: 4, name: 'Site' } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    const startEditor = editCell(container, 'start')
    fireEvent.input(startEditor, { target: { value: '1300' } })
    fireEvent.keyDown(startEditor, { key: 'Enter' })

    await waitFor(() => expect(getByRole('gridcell', { name: '13:00' })).toBeInTheDocument())

    unmount()
  })

  it('opens the bulk-entry modal from the toolbar', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: /Bulk entry/i }))

    // The bulk-entry form (portalled dialog) appears without leaving the grid.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Bulk entry/i)).toBeInTheDocument()

    unmount()
  })

  it('Add inherits the latest end only when the latest entry is from today (suggest-time on)', async () => {
    window.APP_CONFIG!.suggestTime = true
    try {
      const now = new Date()
      const today = `${String(now.getDate()).padStart(2, '0')}/${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()}`
      mockApiWith([{ entry: { ...DEFAULT_ENTRY, date: today, end: '10:30', class: 0 } }])
      const { getByRole, container, unmount } = renderTracking()
      await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

      fireEvent.click(getByRole('button', { name: 'Add entry' }))
      // The latest entry is from today → the new row's start inherits its end.
      await waitFor(() => expect(container.querySelector('tbody td[data-col-key="start"]')?.textContent).toBe('10:30'))

      unmount()
    } finally {
      window.APP_CONFIG!.suggestTime = false
    }
  })

  it('Add starts at the current time when the latest entry is from a prior day (suggest-time on)', async () => {
    window.APP_CONFIG!.suggestTime = true
    try {
      mockApiWith([{ entry: { ...DEFAULT_ENTRY, date: '01/01/2020', end: '10:30', class: 0 } }])
      const { getByRole, container, unmount } = renderTracking()
      await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

      fireEvent.click(getByRole('button', { name: 'Add entry' }))
      // A fresh day must NOT inherit the prior day's end — it starts at the current time.
      await waitFor(() => expect(container.querySelector('tbody td[data-col-key="start"]')?.textContent).toMatch(/^\d{2}:\d{2}$/))
      expect(container.querySelector('tbody td[data-col-key="start"]')?.textContent).not.toBe('10:30')

      unmount()
    } finally {
      window.APP_CONFIG!.suggestTime = false
    }
  })

  it('shows the save + reset actions on an unsaved new row, but not on a clean saved row', async () => {
    mockApi()
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    // The saved, untouched row keeps both unsaved actions in their reserved (hidden) slots.
    const savedRow = container.querySelector('tbody tr.tracking-row')!
    expect(savedRow.querySelector('.is-unsaved')!.classList.contains('action-slot-hidden')).toBe(true)
    expect(savedRow.querySelector('.is-reset')!.classList.contains('action-slot-hidden')).toBe(true)

    fireEvent.click(getByRole('button', { name: 'Add entry' }))
    // The new row is prepended; its save + reset actions are visible immediately.
    const newRow = container.querySelector('tbody tr.tracking-row')!
    expect(newRow.classList.contains('is-new')).toBe(true)
    expect(newRow.querySelector('.is-unsaved')!.classList.contains('action-slot-hidden')).toBe(false)
    expect(newRow.querySelector('.is-reset')!.classList.contains('action-slot-hidden')).toBe(false)

    unmount()
  })

  it('reset removes an unsaved new row without calling delete', async () => {
    mockApi()
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())
    const rowCount = (): number => container.querySelectorAll('tbody tr.tracking-row').length
    const initial = rowCount()

    fireEvent.click(getByRole('button', { name: 'Add entry' }))
    expect(rowCount()).toBe(initial + 1)

    // Discarding the new row drops it client-side — no /tracking/delete with a temp id.
    fireEvent.click(container.querySelector<HTMLElement>('tbody tr.tracking-row .is-reset')!)

    await waitFor(() => expect(rowCount()).toBe(initial))
    expect(postForm).not.toHaveBeenCalledWith('/tracking/delete', expect.anything())

    unmount()
  })

  it('reset reverts an edited existing row to its DB value without saving', async () => {
    // An incomplete row (no customer) won't auto-save, so the edit stays pending.
    mockApiWith([{ entry: { ...DEFAULT_ENTRY, customer: 0, class: 0 } }])
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    const descEditor = editCell(container, 'description')
    fireEvent.input(descEditor, { target: { value: 'changed' } })
    fireEvent.keyDown(descEditor, { key: 'Enter' })
    await waitFor(() => expect(getByRole('gridcell', { name: 'changed' })).toBeInTheDocument())

    // Reset throws the edit away: the cell shows the saved value and nothing is POSTed.
    fireEvent.click(container.querySelector<HTMLElement>('tbody tr.tracking-row .is-reset')!)

    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())
    expect(postJson).not.toHaveBeenCalledWith('/tracking/save', expect.anything())

    unmount()
  })

  it('the refresh toolbar button refetches the entries', async () => {
    mockApi()
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())
    const entryFetches = (): number => getJson.mock.calls.filter((args) => String(args[0]).startsWith('/getData/days/')).length
    const before = entryFetches()

    fireEvent.click(getByRole('button', { name: 'Refresh' }))

    await waitFor(() => expect(entryFetches()).toBeGreaterThan(before))

    unmount()
  })

  it('the picker offers only active customers (inactive ones are hidden)', async () => {
    mockTracking({
      entries: [{ entry: DEFAULT_ENTRY }],
      customers: [
        { customer: { id: 1, name: 'ACME', active: true } },
        { customer: { id: 7, name: 'OldCo', active: false } },
      ],
      projects: [{ project: { id: 4, name: 'Site', customer: 1, active: true } }],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    // Add opens the customer picker on the new row.
    fireEvent.click(getByRole('button', { name: 'Add entry' }))
    await waitFor(() => expect(document.querySelectorAll('.combobox-content .combobox-item').length).toBeGreaterThan(0))
    const labels = [...document.querySelectorAll('.combobox-content .combobox-item')].map((el) => el.textContent ?? '')
    expect(labels.some((label) => label.includes('ACME'))).toBe(true)
    expect(labels.some((label) => label.includes('OldCo'))).toBe(false)

    unmount()
  })

  it('keeps an existing entry\'s deactivated project selectable in its picker', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, customer: 1, project: 9 } }],
      customers: [{ customer: { id: 1, name: 'ACME', active: true } }],
      projects: [
        { project: { id: 4, name: 'Site', customer: 1, active: true } },
        { project: { id: 9, name: 'Legacy', customer: 1, active: false } }, // the entry's now-inactive project
      ],
      activities: [{ activity: { id: 5, name: 'Dev' } }],
    })
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    // The existing entry's project picker shows active projects PLUS its current (inactive) one.
    editCell(container, 'project')
    await waitFor(() => expect(document.querySelectorAll('.combobox-content .combobox-item').length).toBeGreaterThan(0))
    const labels = [...document.querySelectorAll('.combobox-content .combobox-item')].map((el) => el.textContent ?? '')
    expect(labels.some((label) => label.includes('Site'))).toBe(true)
    expect(labels.some((label) => label.includes('Legacy'))).toBe(true)

    unmount()
  })

  it('Add pre-fills the end time to start + the configured minimum (suggest-time on)', async () => {
    window.APP_CONFIG!.suggestTime = true
    window.APP_CONFIG!.minEntryDuration = 15
    try {
      const now = new Date()
      const today = `${String(now.getDate()).padStart(2, '0')}/${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()}`
      // Latest entry from today ends 10:30 → start inherits 10:30 → end = 10:30 + 15 = 10:45.
      mockApiWith([{ entry: { ...DEFAULT_ENTRY, date: today, end: '10:30', class: 0 } }])
      const { getByRole, container, unmount } = renderTracking()
      await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

      fireEvent.click(getByRole('button', { name: 'Add entry' }))
      await waitFor(() => expect(container.querySelector('tbody td[data-col-key="start"]')?.textContent).toBe('10:30'))
      expect(container.querySelector('tbody td[data-col-key="end"]')?.textContent).toBe('10:45')

      unmount()
    } finally {
      window.APP_CONFIG!.suggestTime = false
      window.APP_CONFIG!.minEntryDuration = 5
    }
  })

  it('Continue saves the pre-filled entry on commit even though nothing was edited (#495)', async () => {
    window.APP_CONFIG!.suggestTime = true
    window.APP_CONFIG!.minEntryDuration = 15
    try {
      const now = new Date()
      const today = `${String(now.getDate()).padStart(2, '0')}/${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()}`
      // A saved entry from today ending 10:30 → Continue seeds start 10:30, end 10:45.
      mockApiWith([{ entry: { ...DEFAULT_ENTRY, date: today, end: '10:30', class: 0 } }])
      postJson.mockResolvedValue({})
      const { getByRole, container, unmount } = renderTracking()
      await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

      // Continue → a new row pre-filled with the source's customer/project/activity/
      // ticket, start inherited (10:30) and end = start + 15 (10:45). Its draft equals
      // its seed (nothing edited) — the case that used to be dropped as a no-op.
      fireEvent.click(getByRole('button', { name: 'Continue' }))
      const startInput = await waitFor(() => {
        const el = container.querySelector<HTMLInputElement>('tbody td[data-col-key="start"] input')
        if (el === null) throw new Error('start editor not open on the Continue row')

        return el
      })
      expect(startInput.value).toBe('10:30')

      // Commit the start cell with its seeded value unchanged — the draft still
      // equals its seed (the #495 case), and the complete new row must SAVE.
      fireEvent.input(startInput, { target: { value: '10:30' } })
      fireEvent.keyDown(startInput, { key: 'Enter' })


      await waitFor(() =>
        expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({
          customer: 1, project: 4, activity: 5, ticket: 'ABC-1', start: '10:30', end: '10:45',
        })),
      )
      // A brand-new entry carries no id in the save payload.
      expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.not.objectContaining({ id: expect.anything() }))

      unmount()
    } finally {
      window.APP_CONFIG!.suggestTime = false
      window.APP_CONFIG!.minEntryDuration = 5
    }
  })

  it('gates the ticket link behind Ctrl/Cmd — a plain click is prevented (starts editing)', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, id: 1, date: '16/06/2026', ticket: 'ABC-1', customer: 1, project: 4 } }],
    })
    const { container, unmount } = renderTracking()
    await waitFor(() => expect(container.querySelector('a.ticket-link')).toBeInTheDocument())
    const link = container.querySelector('a.ticket-link') as HTMLAnchorElement

    // Plain click: navigation prevented — the click bubbles to the cell and
    // starts inline editing instead of opening the ticket system.
    const plain = new MouseEvent('click', { bubbles: true, cancelable: true })
    link.dispatchEvent(plain)
    expect(plain.defaultPrevented).toBe(true)

    // Ctrl+click (the activation key): the link keeps its default behaviour.
    const modified = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true })
    link.dispatchEvent(modified)
    expect(modified.defaultPrevented).toBe(false)

    unmount()
  })

  it('offers every row action in the kebab menu and fires the chosen one', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, id: 1, date: '16/06/2026', ticket: 'ABC-1', customer: 1, project: 4 } }],
    })
    const { container, getByRole, getAllByRole, unmount } = renderTracking()
    await waitFor(() => expect(container.querySelector('.action-menu')).toBeInTheDocument())

    // Open via click (touch/keyboard path; hover is a pointer-only accelerator).
    fireEvent.click(getByRole('button', { name: 'Row actions' }))
    const menu = await waitFor(() => {
      const pop = container.querySelector('.action-menu-pop')
      expect(pop).toBeInTheDocument()

      return pop as HTMLElement
    })
    // Saved latest row: Continue, Prolong, Info, Delete.
    expect(menu.querySelectorAll("[role='menuitem']").length).toBe(4)

    // Choosing Continue clones the row (same effect as the icon button) and closes the menu.
    fireEvent.click(getByRole('menuitem', { name: /Continue/ }))
    await waitFor(() => expect(getAllByRole('row').length).toBeGreaterThan(2))
    expect(container.querySelector('.action-menu-pop')).not.toBeInTheDocument()

    unmount()
  })

  it('closes the kebab menu with Escape', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, id: 1, date: '16/06/2026', ticket: 'ABC-1', customer: 1, project: 4 } }],
    })
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(container.querySelector('.action-menu')).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Row actions' }))
    await waitFor(() => expect(container.querySelector('.action-menu-pop')).toBeInTheDocument())

    fireEvent.keyDown(container.querySelector('.action-menu-pop')!, { key: 'Escape' })
    await waitFor(() => expect(container.querySelector('.action-menu-pop')).not.toBeInTheDocument())

    unmount()
  })

  it('gives the kebab hover menu a close grace period (survives a brief pointer exit)', async () => {
    mockTracking({
      entries: [{ entry: { ...DEFAULT_ENTRY, id: 1, date: '16/06/2026', ticket: 'ABC-1', customer: 1, project: 4 } }],
    })
    const { container, unmount } = renderTracking()
    await waitFor(() => expect(container.querySelector('.action-menu')).toBeInTheDocument())
    const wrapper = container.querySelector('.action-menu')!

    // pointerenter/leave don't bubble and jsdom has no PointerEvent — dispatch
    // plain events carrying the pointerType the handlers check.
    const pointer = (type: string): Event => Object.assign(new Event(type), { pointerType: 'mouse' })

    // Hover opens (Solid signals apply synchronously).
    wrapper.dispatchEvent(pointer('pointerenter'))
    expect(container.querySelector('.action-menu-pop')).toBeInTheDocument()

    vi.useFakeTimers()
    try {
      // Leaving does NOT close immediately — the cursor may be crossing the
      // small gap between the button and the popup.
      wrapper.dispatchEvent(pointer('pointerleave'))
      expect(container.querySelector('.action-menu-pop')).toBeInTheDocument()
      vi.advanceTimersByTime(100)
      expect(container.querySelector('.action-menu-pop')).toBeInTheDocument()

      // Re-entering within the grace period cancels the pending close for good.
      wrapper.dispatchEvent(pointer('pointerenter'))
      vi.advanceTimersByTime(500)
      expect(container.querySelector('.action-menu-pop')).toBeInTheDocument()

      // Leaving for longer than the grace period closes it.
      wrapper.dispatchEvent(pointer('pointerleave'))
      vi.advanceTimersByTime(200)
      expect(container.querySelector('.action-menu-pop')).not.toBeInTheDocument()
    } finally {
      vi.useRealTimers()
    }

    unmount()
  })
})
