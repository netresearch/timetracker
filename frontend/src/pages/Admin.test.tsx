import { createMemoryHistory, MemoryRouter, Route } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { ApiError } from '../api/client'
import Admin from './Admin'

const getJson = vi.fn()
const postJson = vi.fn()

vi.mock('../api/client', () => {
  class MockApiError extends Error {
    constructor(public status: number, message: string) {
      super(message)
      this.name = 'ApiError'
    }
  }

  return {
    SessionExpiredError: class extends Error {},
    ApiError: MockApiError,
    apiErrorMessage: (error: unknown, fallback: string) => (error instanceof MockApiError ? error.message : fallback),
    getJson: (...args: unknown[]) => getJson(...args),
    postJson: (...args: unknown[]) => postJson(...args),
  }
})

function mockEndpoints() {
  getJson.mockImplementation((path: string) => {
    switch (path) {
      case '/getAllCustomers':
        return Promise.resolve([{ customer: { id: 1, name: 'ACME', active: true, global: false, teams: [2] } }])
      case '/getAllTeams':
        return Promise.resolve([{ team: { id: 2, name: 'Backend', lead_user_id: 3 } }])
      case '/getAllUsers':
        return Promise.resolve([{ user: { id: 3, username: 'jdoe', abbr: 'JD', type: 'DEV', teams: [2] } }])
      case '/getAllProjects':
        return Promise.resolve([{ project: { id: 4, name: 'Site', customer: 1, jiraId: 'ABC', active: true, global: false } }])
      case '/getActivities':
        return Promise.resolve([{ activity: { id: 5, name: 'Dev', needsTicket: false, factor: 1 } }])
      case '/getTicketSystems':
        return Promise.resolve([{ ticketSystem: { id: 6, name: 'Jira', type: 'JIRA', bookTime: true, url: 'https://x' } }])
      default:
        return Promise.resolve([])
    }
  })
}

function renderAdmin() {
  const history = createMemoryHistory()
  history.set({ value: '/admin' })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(() => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter history={history}>
        <Route path="/admin" component={Admin} />
      </MemoryRouter>
    </QueryClientProvider>
  ))
}

afterEach(() => {
  getJson.mockReset()
  postJson.mockReset()
  vi.restoreAllMocks()
})

describe('Admin', () => {
  it('lists the first entity (customers) and renders relation columns', async () => {
    mockEndpoints()
    const { getByRole, getByText, unmount } = renderAdmin()

    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())
    // teams relation column resolves id 2 → "Backend"
    expect(getByText('Backend')).toBeInTheDocument()

    unmount()
  })

  it('switches entity via the sub-nav', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Users' }))
    await waitFor(() => expect(getByRole('gridcell', { name: 'jdoe' })).toBeInTheDocument())

    unmount()
  })

  it('saves a new customer as typed JSON', async () => {
    mockEndpoints()
    postJson.mockResolvedValue([7, 'New', true, false, []])
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Add' }))
    // The dialog is portalled to document.body (Ark UI); screen queries the
    // whole document, and the name field is the dialog's only textbox.
    const name = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(name, { target: { value: 'New' } })
    fireEvent.submit(name.closest('form') as HTMLFormElement)

    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/customer/save', expect.objectContaining({ name: 'New', active: true, global: false })),
    )

    unmount()
  })

  it('does not crash on list rows with a missing or null wrapper', async () => {
    // A prod list row whose rowKey wrapper is absent/null must not crash the
    // grid (regression: it read `.name` off an undefined unwrapped row).
    getJson.mockImplementation((path: string) => {
      switch (path) {
        case '/getAllCustomers':
          return Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: false, teams: [] } },
            { customer: null },
            {},
          ])
        default:
          return Promise.resolve([])
      }
    })
    const { getByRole, queryAllByRole, unmount } = renderAdmin()

    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())
    // Only the one well-formed customer renders; the malformed rows are dropped.
    expect(queryAllByRole('row').length).toBe(2) // header + 1 data row

    unmount()
  })

  it('filters the list by the free-text query', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: true, teams: [] } },
            { customer: { id: 2, name: 'Globex', active: true, global: true, teams: [] } },
          ])
        : Promise.resolve([]),
    )
    const { getByRole, queryByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.input(getByRole('searchbox'), { target: { value: 'glob' } })

    await waitFor(() => expect(queryByRole('cell', { name: 'ACME' })).not.toBeInTheDocument())
    expect(getByRole('gridcell', { name: 'Globex' })).toBeInTheDocument()

    unmount()
  })

  it('deletes a row after the confirm dialog is accepted', async () => {
    mockEndpoints()
    postJson.mockResolvedValue(null)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Delete' }))

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/customer/delete', { id: 1 }))
    unmount()
  })

  it('does not delete when the confirm dialog is cancelled', async () => {
    mockEndpoints()
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Delete' }))

    expect(postJson).not.toHaveBeenCalled()
    unmount()
  })

  it('keeps the dialog open and shows the server error message on save failure', async () => {
    mockEndpoints()
    postJson.mockRejectedValue(new ApiError(422, 'Name taken'))
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Add' }))
    const name = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(name, { target: { value: 'New' } })
    const form = name.closest('form') as HTMLFormElement
    fireEvent.submit(form)

    // The dialog stays open and shows the server error inside the form.
    await waitFor(() => expect(form.querySelector('[role=alert]')).toHaveTextContent('Name taken'))
    expect(form).toBeInTheDocument()
    unmount()
  })

  it('cycles a column header through ascending, descending and unsorted', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const header = getByRole('button', { name: /Name/ })
    const th = () => header.closest('th')
    expect(th()).toHaveAttribute('aria-sort', 'none')
    fireEvent.click(header)
    expect(th()).toHaveAttribute('aria-sort', 'ascending')
    fireEvent.click(header)
    expect(th()).toHaveAttribute('aria-sort', 'descending')
    fireEvent.click(header)
    expect(th()).toHaveAttribute('aria-sort', 'none')
    unmount()
  })

  it('shows the load-error fallback when the list request fails', async () => {
    getJson.mockRejectedValue(new ApiError(500, 'boom'))
    const { findByRole, unmount } = renderAdmin()

    expect(await findByRole('alert')).toBeInTheDocument()
    unmount()
  })

  it('has no automatically detectable accessibility violations', async () => {
    mockEndpoints()
    const { container, getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })
})
