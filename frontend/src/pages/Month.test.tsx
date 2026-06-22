import { fireEvent, waitFor } from '@solidjs/testing-library'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import type { HolidayRecord, WorktimeRecord } from '../api/queries'
import { renderWithProviders } from '../test/renderWithProviders'
import Month, { resolveDayTokens } from './Month'

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
  return renderWithProviders(undefined, {
    route: { initialPath: '/month?year=2026&month=6', path: '/month', component: Month },
  })
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

describe('resolveDayTokens', () => {
  const now = new Date(2026, 5, 15) // Mon 2026-06-15 (local)

  it('resolves "today" to the local day key', () => {
    expect(resolveDayTokens('today', now)).toEqual(['2026-06-15'])
  })

  it('resolves "current-week" to the local Mon–Sun keys', () => {
    expect(resolveDayTokens('current-week', now)).toEqual([
      '2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18', '2026-06-19', '2026-06-20', '2026-06-21',
    ])
  })

  it('resolves "current-month" to every day of the local month', () => {
    const keys = resolveDayTokens('current-month', now)
    expect(keys).toHaveLength(30)
    expect(keys[0]).toBe('2026-06-01')
    expect(keys.at(-1)).toBe('2026-06-30')
  })

  it('passes through an explicit CSV of keys', () => {
    expect(resolveDayTokens('2026-06-01, 2026-06-02 ,', now)).toEqual(['2026-06-01', '2026-06-02'])
  })
})
