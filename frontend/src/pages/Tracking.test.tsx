import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { fireEvent, render, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import Tracking from './Tracking'

const getJson = vi.fn()

vi.mock('../api/client', () => ({
  SessionExpiredError: class extends Error {},
  ApiError: class extends Error {},
  getJson: (...args: unknown[]) => getJson(...args),
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
  vi.restoreAllMocks()
})

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
})
