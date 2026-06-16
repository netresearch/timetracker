import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { fireEvent, render, waitFor } from '@solidjs/testing-library'
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

function mockApi() {
  getJson.mockImplementation((path: string) => {
    if (path.startsWith('/getData/days/')) {
      return Promise.resolve([
        {
          entry: {
            id: 1, date: '16/06/2026', start: '09:00', end: '10:30', user: 3,
            customer: 1, project: 4, activity: 5, description: 'Work', ticket: 'ABC-1',
            duration: '1:30', durationMinutes: 90, class: 8, worklog: null, extTicket: null,
          },
        },
      ])
    }
    switch (path) {
      case '/getCustomers':
        return Promise.resolve([{ customer: { id: 1, name: 'ACME' } }])
      case '/getAllProjects':
        return Promise.resolve([{ project: { id: 4, name: 'Site' } }])
      case '/getActivities':
        return Promise.resolve([{ activity: { id: 5, name: 'Dev' } }])
      default:
        return Promise.resolve([])
    }
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
    getJson.mockImplementation((path: string) => {
      if (path.startsWith('/getData/days/')) {
        return Promise.resolve([{ entry: { id: 1, date: '16/06/2026', start: '09:00', end: '10:30', user: 3, customer: 0, project: 0, activity: 5, description: 'Work', ticket: '', duration: '1:30', durationMinutes: 90, class: 0, worklog: null, extTicket: null } }])
      }
      switch (path) {
        case '/getCustomers':
          return Promise.resolve([{ customer: { id: 7, name: 'ACME' } }])
        case '/getAllProjects':
          return Promise.resolve([{ project: { id: 9, name: 'Apollo', customer: 7, jiraId: 'APO' } }])
        case '/getActivities':
          return Promise.resolve([{ activity: { id: 5, name: 'Dev' } }])
        default:
          return Promise.resolve([])
      }
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
    getJson.mockImplementation((path: string) => {
      if (path.startsWith('/getData/days/')) {
        return Promise.resolve([{ entry: { id: 1, date: '16/06/2026', start: '09:00', end: '10:30', user: 3, customer: 7, project: 9, activity: 5, description: 'Work', ticket: '', duration: '1:30', durationMinutes: 90, class: 0, worklog: null, extTicket: null } }])
      }
      switch (path) {
        case '/getCustomers':
          return Promise.resolve([{ customer: { id: 7, name: 'ACME' } }])
        case '/getAllProjects':
          return Promise.resolve([
            { project: { id: 9, name: 'Apollo', customer: 7, jiraId: 'APO' } },
            { project: { id: 10, name: 'Zeus', customer: 8, jiraId: 'ZEU' } },
          ])
        case '/getActivities':
          return Promise.resolve([{ activity: { id: 5, name: 'Dev' } }])
        default:
          return Promise.resolve([])
      }
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
})
