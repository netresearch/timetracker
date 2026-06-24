import { fireEvent, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import Auswertung from './Auswertung'

const getJson = vi.fn()

vi.mock('../api/client', () => ({
  SessionExpiredError: class extends Error {},
  ApiError: class extends Error {},
  getJson: (...args: unknown[]) => getJson(...args),
}))

function mockEndpoints() {
  getJson.mockImplementation((path: string) => {
    if (path === '/interpretation/customer')
      return Promise.resolve([
        { id: 1, name: 'ACME', hours: 8, quota: '80.00%' },
        { id: 2, name: 'Globex', hours: 2, quota: '20.00%' },
      ])
    if (path === '/interpretation/time')
      return Promise.resolve([{ id: null, name: '26-06-01', day: '01.06.', hours: 4, quota: '100.00%', expected: 8 }])
    if (path === '/interpretation/entries')
      return Promise.resolve([
        { entry: { id: 5, date: '01/06/2026', ticket: 'ABC-1', description: 'work', duration: '8:00', quota: '100.00%' } },
      ])
    if (path.startsWith('/interpretation/')) return Promise.resolve([])
    // dropdown endpoints
    if (path === '/getAllCustomers') return Promise.resolve([{ customer: { id: 1, name: 'ACME' } }])

    return Promise.resolve([])
  })
}

function renderPage() {
  return renderWithProviders(undefined, {
    route: { initialPath: '/auswertung', component: Auswertung },
  })
}

afterEach(() => getJson.mockReset())

describe('Auswertung', () => {
  it('auto-loads for the current user and renders the effort charts and entries', async () => {
    mockEndpoints()
    const { getByText, getByRole, unmount } = renderPage()

    // user defaults to self (config.userId=1) → criteria met → charts load.
    await waitFor(() => expect(getByRole('rowheader', { name: 'ACME' })).toBeInTheDocument())
    expect(getByRole('heading', { name: 'Effort by customer' })).toBeInTheDocument()
    expect(getByText('80.00%')).toBeInTheDocument()
    // entries table
    expect(getByRole('gridcell', { name: 'ABC-1' })).toBeInTheDocument()

    unmount()
  })

  it('applies a changed filter on refresh', async () => {
    mockEndpoints()
    const { getByRole, container, unmount } = renderPage()
    await waitFor(() => expect(getByRole('rowheader', { name: 'ACME' })).toBeInTheDocument())

    getJson.mockClear()
    // Change a filter so the query key actually changes, then submit.
    const ticket = container.querySelector('input[type=text]') as HTMLInputElement
    fireEvent.input(ticket, { target: { value: 'ABC-1' } })
    fireEvent.click(getByRole('button', { name: 'Refresh' }))

    await waitFor(() =>
      expect(getJson).toHaveBeenCalledWith('/interpretation/customer', expect.objectContaining({ ticket: 'ABC-1', user: 1 })),
    )

    unmount()
  })

  it('applies a date-range preset immediately and marks it active', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderPage()
    await waitFor(() => expect(getByRole('rowheader', { name: 'ACME' })).toBeInTheDocument())

    getJson.mockClear()
    fireEvent.click(getByRole('button', { name: 'Last year' }))

    const y = new Date().getFullYear() - 1
    await waitFor(() =>
      expect(getJson).toHaveBeenCalledWith('/interpretation/customer', expect.objectContaining({ datestart: `${y}-01-01`, dateend: `${y}-12-31` })),
    )
    expect(getByRole('button', { name: 'Last year' })).toHaveAttribute('aria-pressed', 'true')

    unmount()
  })

  it('sorts the detail table chronologically by ISO date, not display text', async () => {
    getJson.mockImplementation((path: string) => {
      if (path === '/interpretation/time')
        return Promise.resolve([{ id: null, name: '26-06-24', day: '24.06.', hours: 4, quota: '100.00%', expected: 8 }])
      if (path === '/interpretation/entries')
        return Promise.resolve([
          { entry: { id: 1, date: '01/07/2026', ticket: 'JUL-01', description: 'b', duration: '2:00', quota: '' } },
          { entry: { id: 2, date: '24/06/2026', ticket: 'JUN-24', description: 'a', duration: '8:00', quota: '' } },
        ])
      if (path.startsWith('/interpretation/')) return Promise.resolve([{ id: 1, name: 'ACME', hours: 8, quota: '80.00%' }])

      return Promise.resolve([])
    })
    const { getByRole, container, unmount } = renderPage()
    await waitFor(() => expect(getByRole('gridcell', { name: 'JUL-01' })).toBeInTheDocument())

    const firstTicket = () => container.querySelector('.data-table tbody tr:first-child td:nth-child(2)')?.textContent
    // Backend order keeps JUL-01 first until we sort.
    expect(firstTicket()).toBe('JUL-01')

    // Ascending by date → the earlier calendar day (24 Jun) leads, proving the
    // sort keys on the ISO date, not the localized "01.07." display string (which
    // a naive string sort would order before "24.06.").
    fireEvent.click(getByRole('button', { name: 'Date' }))
    await waitFor(() => expect(firstTicket()).toBe('JUN-24'))

    // A second click flips to descending.
    fireEvent.click(getByRole('button', { name: 'Date' }))
    await waitFor(() => expect(firstTicket()).toBe('JUL-01'))

    unmount()
  })

  it('has no automatically detectable accessibility violations', async () => {
    mockEndpoints()
    const { container, getByRole, unmount } = renderPage()
    await waitFor(() => expect(getByRole('rowheader', { name: 'ACME' })).toBeInTheDocument())

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })
})
