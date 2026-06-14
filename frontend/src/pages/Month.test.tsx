import { createMemoryHistory, MemoryRouter, Route } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { fireEvent, render, waitFor } from '@solidjs/testing-library'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import type { HolidayRecord, WorktimeRecord } from '../api/queries'
import Month from './Month'

// One booking in the past, one in the future (Fri 2026-06-19) relative to the
// frozen "today" — the until-today summary must ignore the future one.
const times: WorktimeRecord[] = [
  { id: null, name: '26-06-01', day: '01.06.', hours: 8, quota: '50%' },
  { id: null, name: '26-06-19', day: '19.06.', hours: 8, quota: '50%' },
]
const holidays: HolidayRecord[] = [
  { holiday: { name: 'Pfingstmontag', date: '2026-06-01' } },
]

vi.mock('../api/client', () => ({
  SessionExpiredError: class extends Error {},
  getJson: vi.fn((path: string) =>
    Promise.resolve(path === '/interpretation/time' ? times : holidays),
  ),
}))

function renderMonth() {
  const history = createMemoryHistory()
  history.set({ value: '/month?year=2026&month=6' })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(() => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter history={history}>
        <Route path="/month" component={Month} />
      </MemoryRouter>
    </QueryClientProvider>
  ))
}

describe('Month page', () => {
  beforeAll(() => {
    // Only Date is faked: real timers keep waitFor/solid-query working.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 5, 12))
  })

  afterAll(() => {
    vi.useRealTimers()
  })

  it('renders a calendar cell per day plus the holiday label', async () => {
    const { container, getAllByRole, getByText, getByRole, unmount } = renderMonth()

    await waitFor(() => {
      expect(getAllByRole('table')).toHaveLength(2)
    })

    // June 2026: 30 day cells across 5 calendar weeks, plus 5 summary rows.
    expect(container.querySelectorAll('td.cell')).toHaveLength(30)
    expect(container.querySelectorAll('tbody tr')).toHaveLength(10)
    expect(getByText('Pfingstmontag')).toBeInTheDocument()
    // Month chips: past months are links, future months are not.
    expect(getByRole('link', { name: 'May' })).toBeInTheDocument()
    expect(getByText('Jul').closest('a')).toBeNull()

    unmount()
  })

  it('excludes future bookings from the until-today summary', async () => {
    const { getAllByRole, getAllByText, getByText, unmount } = renderMonth()

    await waitFor(() => {
      expect(getAllByRole('table')).toHaveLength(2)
    })

    // 21 working days (holiday on Mon 1st), 9 of them until the frozen 12th.
    // Until today: 8h worked (on the holiday) - 9 * 8h expected = -64:00
    // (shown in the ring AND the summary row); the 8h booked on the future
    // 19th must not count yet.
    expect(getAllByText('-64:00').length).toBeGreaterThanOrEqual(2)
    // Whole month: 16h worked - 21 * 8h expected = -152:00.
    expect(getByText('-152:00')).toBeInTheDocument()

    unmount()
  })

  it('scopes the summary to a clicked day and toggles it back off', async () => {
    const { container, getByText, queryByText, getAllByText, unmount } = renderMonth()
    await waitFor(() => expect(container.querySelectorAll('td.cell')).toHaveLength(30))

    // Whole-month balance is shown initially.
    expect(getByText('-152:00')).toBeInTheDocument()

    // Click Tue 2026-06-02 (a working day: 0 worked, 8h expected → -08:00).
    const dayButtons = [...container.querySelectorAll<HTMLButtonElement>('button.cell-btn')]
    const june2 = dayButtons.find((b) => b.querySelector('.cell-num')?.textContent === '2')
    fireEvent.click(june2 as HTMLButtonElement)

    await waitFor(() => expect(getByText('1 selected')).toBeInTheDocument())
    // Summary now reflects only that day: -08:00 balance (ring + row), month total gone.
    expect(getAllByText('-08:00').length).toBeGreaterThanOrEqual(2)
    expect(queryByText('-152:00')).not.toBeInTheDocument()
    expect(june2).toHaveAttribute('aria-pressed', 'true')

    // Clicking again deselects → back to the whole-month scope.
    fireEvent.click(june2 as HTMLButtonElement)
    await waitFor(() => expect(getByText('-152:00')).toBeInTheDocument())
    expect(june2).toHaveAttribute('aria-pressed', 'false')

    unmount()
  })

  it('has no automatically detectable accessibility violations', async () => {
    const { container, getAllByRole, unmount } = renderMonth()

    await waitFor(() => {
      expect(getAllByRole('table')).toHaveLength(2)
    })

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })
})
