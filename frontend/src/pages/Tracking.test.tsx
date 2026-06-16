import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { fireEvent, render, screen, waitFor, within } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import Tracking from './Tracking'

const getJson = vi.fn()
const postJson = vi.fn()

vi.mock('../api/client', () => ({
  SessionExpiredError: class extends Error {},
  ApiError: class extends Error {},
  apiErrorMessage: (error: unknown, fallback: string) => (error instanceof Error ? error.message : fallback),
  getJson: (...args: unknown[]) => getJson(...args),
  postJson: (...args: unknown[]) => postJson(...args),
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

function mockApi() {
  mockTracking({
    entries: [{ entry: DEFAULT_ENTRY }],
    customers: [{ customer: { id: 1, name: 'ACME' } }],
    projects: [{ project: { id: 4, name: 'Site' } }],
    activities: [{ activity: { id: 5, name: 'Dev' } }],
  })
}

function renderTracking() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(() => (
    <QueryClientProvider client={queryClient}>
      <Tracking />
    </QueryClientProvider>
  ))
}

afterEach(() => {
  getJson.mockReset()
  postJson.mockReset()
  vi.restoreAllMocks()
})

// Open the inline editor on a column's first cell and return its control.
function editCell(container: HTMLElement, colKey: string): HTMLInputElement {
  const cell = container.querySelector<HTMLElement>(`td[data-col-key="${colKey}"]`)
  if (cell === null) {
    throw new Error(`no cell for ${colKey}`)
  }
  cell.focus()
  fireEvent.keyDown(cell, { key: 'Enter' })
  const control = cell.querySelector<HTMLInputElement>('input, select')
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

  it('has no automatically detectable accessibility violations', async () => {
    mockApi()
    const { container, getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })

  it('saves the whole entry to /tracking/save on row-leave', async () => {
    mockApi()
    postJson.mockResolvedValue({})
    const { getByRole, container, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    const editor = editCell(container, 'ticket')
    fireEvent.input(editor, { target: { value: 'xyz-9' } })
    fireEvent.keyDown(editor, { key: 'Enter' })
    // Nothing saved until the row is left.
    expect(postJson).not.toHaveBeenCalled()

    // Leave the table (focus the days selector) → the dirty row saves once.
    ;(getByRole('combobox') as HTMLElement).focus()
    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/tracking/save', expect.objectContaining({
        id: 1, ticket: 'XYZ-9', date: '2026-06-16', start: '09:00', end: '10:30', customer: 1, project: 4, activity: 5,
      })),
    )
    expect(postJson).toHaveBeenCalledTimes(1)

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

  it('deletes an entry via /tracking/delete after confirmation', async () => {
    mockApi()
    postJson.mockResolvedValue({ success: true })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Delete' }))

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/tracking/delete', { id: 1 }))

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

    const select = editCell(container, 'project')
    const labels = Array.from(select.querySelectorAll('option')).map((option) => option.textContent)
    expect(labels).toContain('Apollo') // customer 7's project
    expect(labels).not.toContain('Zeus') // customer 8's project is filtered out

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

  it('Alt+I shows the per-scope summary popup', async () => {
    mockApi()
    postJson.mockResolvedValue({
      customer: { scope: 'customer', name: 'ACME', entries: 5, total: 300, own: 120, estimation: 0 },
      project: { scope: 'project', name: 'Site', entries: 3, total: 180, own: 90, estimation: 600 },
      activity: { scope: 'activity', name: 'Dev', entries: 2, total: 120, own: 60, estimation: 0 },
      ticket: { scope: 'ticket', name: 'ABC-1', entries: 1, total: 60, own: 60, estimation: 0 },
    })
    const { getByRole, unmount } = renderTracking()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Work' })).toBeInTheDocument())

    fireEvent.keyDown(document, { key: 'i', altKey: true })

    // The summary dialog is portalled to document.body → query via screen.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('ACME')).toBeInTheDocument()
    expect(within(dialog).getByText('Site')).toBeInTheDocument()
    expect(within(dialog).getByText('Dev')).toBeInTheDocument()
    // estimation shown for the project scope (600 min → 10:00).
    expect(within(dialog).getByText('10:00')).toBeInTheDocument()

    unmount()
  })
})
