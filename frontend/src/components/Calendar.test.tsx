import { fireEvent, render } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { Calendar } from './Calendar'

const TODAY = '2026-07-08'

// Flush the queueMicrotask that moveFocus() uses to focus a (possibly newly
// rendered) day button after Solid has applied the grid update.
const flushFocus = (): Promise<void> => Promise.resolve()

const focusedDay = (root: HTMLElement): HTMLButtonElement | null =>
  root.querySelector<HTMLButtonElement>('button.calendar-day[tabindex="0"]')

const dayButton = (root: HTMLElement, iso: string): HTMLButtonElement | null =>
  root.querySelector<HTMLButtonElement>(`button.calendar-day[data-iso="${iso}"]`)

describe('Calendar', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-08T10:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it("renders the selected value's month", () => {
    const { getByText, container, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    expect(getByText('July 2026')).toBeInTheDocument()
    const selected = dayButton(container, '2026-07-15')
    expect(selected).not.toBeNull()
    expect(selected).toHaveAttribute('data-selected', '')
    // aria-selected lives on the gridcell wrapper (ARIA-valid there, not on button)
    expect(selected!.closest('[role="gridcell"]')).toHaveAttribute('aria-selected', 'true')
    unmount()
  })

  it('marks today with aria-current="date"', () => {
    const { container, unmount } = render(() => (
      <Calendar value="" todayIso={TODAY} onSelect={() => {}} />
    ))

    const today = dayButton(container, TODAY)
    expect(today).toHaveAttribute('aria-current', 'date')
    expect(today).toHaveAttribute('data-today', '')
    unmount()
  })

  it('calls onSelect with the clicked day ISO', () => {
    const onSelect = vi.fn()
    const { container, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={onSelect} />
    ))

    fireEvent.click(dayButton(container, '2026-07-20')!)
    expect(onSelect).toHaveBeenCalledWith('2026-07-20')
    unmount()
  })

  it('navigates to the previous and next month', () => {
    const { getByLabelText, getByText, queryByText, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    fireEvent.click(getByLabelText('Previous month'))
    expect(getByText('June 2026')).toBeInTheDocument()

    fireEvent.click(getByLabelText('Next month'))
    fireEvent.click(getByLabelText('Next month'))
    expect(getByText('August 2026')).toBeInTheDocument()
    expect(queryByText('June 2026')).toBeNull()
    unmount()
  })

  it('moves focus by day with the arrow keys', async () => {
    const { container, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    const start = focusedDay(container)!
    expect(start).toHaveAttribute('data-iso', '2026-07-15')
    start.focus()

    fireEvent.keyDown(start, { key: 'ArrowRight' })
    await flushFocus()
    expect(document.activeElement).toBe(dayButton(container, '2026-07-16'))

    fireEvent.keyDown(document.activeElement!, { key: 'ArrowDown' })
    await flushFocus()
    expect(document.activeElement).toBe(dayButton(container, '2026-07-23'))
    unmount()
  })

  it('crosses the month boundary with PageDown', async () => {
    const { container, getByText, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    fireEvent.keyDown(focusedDay(container)!, { key: 'PageDown' })
    await flushFocus()
    expect(getByText('August 2026')).toBeInTheDocument()
    expect(document.activeElement).toBe(dayButton(container, '2026-08-15'))
    unmount()
  })

  it('clamps the day when PageDown lands on a shorter month', async () => {
    // 31 Aug → Sep has only 30 days, so the focus clamps to 30 Sep.
    const { container, getByText, unmount } = render(() => (
      <Calendar value="2026-08-31" todayIso={TODAY} onSelect={() => {}} />
    ))

    fireEvent.keyDown(focusedDay(container)!, { key: 'PageDown' })
    await flushFocus()
    expect(getByText('September 2026')).toBeInTheDocument()
    expect(document.activeElement).toBe(dayButton(container, '2026-09-30'))
    unmount()
  })

  it('Home/End jump to the start (Monday) and end (Sunday) of the focused week', async () => {
    // 2026-07-15 is a Wednesday; its week runs Mon 13 → Sun 19.
    const { container, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    const start = focusedDay(container)!
    start.focus()

    fireEvent.keyDown(start, { key: 'Home' })
    await flushFocus()
    expect(document.activeElement).toBe(dayButton(container, '2026-07-13'))

    fireEvent.keyDown(document.activeElement!, { key: 'End' })
    await flushFocus()
    expect(document.activeElement).toBe(dayButton(container, '2026-07-19'))
    unmount()
  })

  it('the Today link returns focus to today after navigating to another month', async () => {
    const { container, getByLabelText, getByText, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    fireEvent.click(getByLabelText('Next month'))
    expect(getByText('August 2026')).toBeInTheDocument()

    fireEvent.click(container.querySelector('.calendar-today-link')!)
    await flushFocus()
    expect(getByText('July 2026')).toBeInTheDocument()
    expect(document.activeElement).toBe(dayButton(container, TODAY))
    unmount()
  })

  it('has no accessibility violations', async () => {
    vi.useRealTimers() // axe-core drives internal timers; don't run it under fake ones
    const { container, unmount } = render(() => (
      <Calendar value="2026-07-15" todayIso={TODAY} onSelect={() => {}} />
    ))

    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })
})
