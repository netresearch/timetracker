import { render } from '@solidjs/testing-library'
import { describe, expect, it } from 'vitest'
import { axe } from 'vitest-axe'

import { formatMinutes } from '../lib/format'
import { EffortChart, type EffortRow } from './EffortChart'

const rows: EffortRow[] = [
  { label: 'Alpha', minutes: 60, quota: '50.00%' },
  { label: 'Beta', minutes: 30, quota: '25.00%' },
  { label: 'Gamma', minutes: 0, quota: '0.00%' },
]

describe('EffortChart', () => {
  it('draws bars proportional to the row maximum', () => {
    const { container, unmount } = render(() => <EffortChart title="Effort" rows={rows} />)
    const bars = [...container.querySelectorAll<HTMLElement>('.effort-bar')]

    expect(bars.map((bar) => bar.style.width)).toEqual(['100%', '50%', '0%'])
    unmount()
  })

  it('clamps the denominator so an all-zero set renders 0% without NaN', () => {
    const { container, unmount } = render(() => <EffortChart title="Effort" rows={[{ label: 'Z', minutes: 0, quota: '0%' }]} />)
    const bar = container.querySelector<HTMLElement>('.effort-bar')

    expect(bar?.style.width).toBe('0%')
    unmount()
  })

  it('formats the hours cell via formatMinutes', () => {
    const { getByRole, unmount } = render(() => <EffortChart title="Effort" rows={[{ label: 'A', minutes: 90, quota: '100%' }]} />)

    expect(getByRole('cell', { name: formatMinutes(90) })).toBeInTheDocument()
    unmount()
  })

  it('renders the empty state and no table when there are no rows', () => {
    const { container, queryByRole, unmount } = render(() => <EffortChart title="Effort" rows={[]} />)

    expect(queryByRole('table')).not.toBeInTheDocument()
    expect(container.querySelector('.effort-empty')).toBeInTheDocument()
    unmount()
  })

  it('is an accessible table (caption + scoped headers) with no axe violations', async () => {
    const { container, unmount } = render(() => <EffortChart title="Effort by customer" rows={rows} />)

    expect(container.querySelector('caption')).toHaveTextContent('Effort by customer')
    expect(container.querySelectorAll('th[scope="col"]').length).toBe(3)
    expect(container.querySelectorAll('th[scope="row"]').length).toBe(rows.length)
    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })

  describe('contract Soll (target)', () => {
    // Mon over Soll (8:30 vs 8:00), Tue under (4:00 vs 8:00).
    const dayRows: EffortRow[] = [
      { label: 'Mon', minutes: 510, quota: '40.00%', target: 480 },
      { label: 'Tue', minutes: 240, quota: '20.00%', target: 480 },
    ]

    it('scales bars to the larger of worked-or-target and marks over/under', () => {
      const { container, unmount } = render(() => <EffortChart title="Effort by day" rows={dayRows} />)
      const bars = [...container.querySelectorAll<HTMLElement>('.effort-bar')]

      // max = max(510, 480, 240, 480) = 510 → Mon fills the track, Tue is 240/510.
      expect(bars[0]?.style.width).toBe('100%')
      expect(bars[1]?.style.width).toBe(`${(240 / 510) * 100}%`)
      expect(bars[0]?.classList.contains('is-over')).toBe(true)
      expect(bars[1]?.classList.contains('is-under')).toBe(true)

      // A Soll marker per row, positioned at target/max.
      const markers = [...container.querySelectorAll<HTMLElement>('.effort-soll-marker')]
      expect(markers).toHaveLength(2)
      expect(markers[0]?.style.left).toBe(`${(480 / 510) * 100}%`)
      unmount()
    })

    it('shows a ghost bar only for under-target rows', () => {
      const { container, unmount } = render(() => <EffortChart title="Effort by day" rows={dayRows} />)
      const ghosts = [...container.querySelectorAll<HTMLElement>('.effort-bar-ghost')]

      // Only Tue (under) gets a ghost, spanning the full Soll extent.
      expect(ghosts).toHaveLength(1)
      expect(ghosts[0]?.style.width).toBe(`${(480 / 510) * 100}%`)
      unmount()
    })

    it('prints the Soll next to the worked time as a non-colour cue', () => {
      const underRow: EffortRow = { label: 'Tue', minutes: 240, quota: '20.00%', target: 480 }
      const { container, unmount } = render(() => <EffortChart title="Effort by day" rows={[underRow]} />)

      expect(container.querySelector('.effort-target')?.textContent).toContain(formatMinutes(480))
      unmount()
    })

    it('omits the Soll overlay entirely for target-less rows', () => {
      const { container, unmount } = render(() => <EffortChart title="Effort by customer" rows={rows} />)

      expect(container.querySelector('.effort-soll-marker')).toBeNull()
      expect(container.querySelector('.effort-bar-ghost')).toBeNull()
      expect(container.querySelector('.effort-bar.is-over')).toBeNull()
      expect(container.querySelector('.effort-target')).toBeNull()
      unmount()
    })

    it('treats a zero Soll (worked weekend/holiday) as a neutral bar, not green over', () => {
      // expected=0 reaches the chart as target:0 (Auswertung always sets target).
      const zeroRow: EffortRow = { label: 'Sat', minutes: 120, quota: '100.00%', target: 0 }
      const { container, unmount } = render(() => <EffortChart title="Effort by day" rows={[zeroRow]} />)

      // No contract boundary for the day → no colour, marker, ghost or Soll text.
      expect(container.querySelector('.effort-bar.is-over')).toBeNull()
      expect(container.querySelector('.effort-bar.is-under')).toBeNull()
      expect(container.querySelector('.effort-soll-marker')).toBeNull()
      expect(container.querySelector('.effort-bar-ghost')).toBeNull()
      expect(container.querySelector('.effort-target')).toBeNull()
      unmount()
    })

    it('classifies worked-exactly-Soll as over (not under) with no ghost', () => {
      const exactRow: EffortRow = { label: 'Wed', minutes: 480, quota: '100.00%', target: 480 }
      const { container, unmount } = render(() => <EffortChart title="Effort by day" rows={[exactRow]} />)

      expect(container.querySelector('.effort-bar.is-over')).not.toBeNull()
      expect(container.querySelector('.effort-bar.is-under')).toBeNull()
      expect(container.querySelector('.effort-bar-ghost')).toBeNull()
      unmount()
    })

    it('stays axe-clean with the Soll overlay', async () => {
      const { container, unmount } = render(() => <EffortChart title="Effort by day" rows={dayRows} />)

      expect(await axe(container)).toHaveNoViolations()
      unmount()
    })
  })
})
