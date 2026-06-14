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
})
