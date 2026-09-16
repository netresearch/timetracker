import { fireEvent, render } from '@solidjs/testing-library'
import { createSignal } from 'solid-js'
import { describe, expect, it } from 'vitest'

import WorklogViewSwitch from './WorklogViewSwitch'
import type { WorklogView } from '../lib/worklogViewPref'

function renderSwitch(initial: WorklogView = 'grouped') {
  const [view, setView] = createSignal<WorklogView>(initial)
  const result = render(() => <WorklogViewSwitch value={view()} onChange={setView} />)

  return { ...result, view }
}

describe('WorklogViewSwitch', () => {
  it('exposes the three views as a radiogroup with one checked', () => {
    const { getAllByRole } = renderSwitch()

    const options = getAllByRole('radio')
    expect(options).toHaveLength(3)
    expect(options.filter((el) => el.getAttribute('aria-checked') === 'true')).toHaveLength(1)
  })

  it('is a single tab stop — only the checked option is reachable by Tab', () => {
    const { getAllByRole } = renderSwitch()

    const tabbable = getAllByRole('radio').filter((el) => el.getAttribute('tabindex') === '0')
    expect(tabbable).toHaveLength(1)
    expect(tabbable[0]?.getAttribute('aria-checked')).toBe('true')
  })

  it('selects on click', () => {
    const { getAllByRole, view } = renderSwitch()

    const flat = getAllByRole('radio').find((el) => el.dataset.worklogView === 'flat')!
    fireEvent.click(flat)

    expect(view()).toBe('flat')
  })

  it('moves the selection with arrow keys and wraps around', () => {
    const { container, view } = renderSwitch('grouped')
    const group = container.querySelector('[role="radiogroup"]')!

    fireEvent.keyDown(group, { key: 'ArrowRight' })
    expect(view()).toBe('flat')

    fireEvent.keyDown(group, { key: 'ArrowRight' })
    expect(view()).toBe('timeline')

    // Past the end wraps to the first, so the group is never a dead end.
    fireEvent.keyDown(group, { key: 'ArrowRight' })
    expect(view()).toBe('grouped')

    fireEvent.keyDown(group, { key: 'ArrowLeft' })
    expect(view()).toBe('timeline')
  })

  it('ignores keys that are not arrows', () => {
    const { container, view } = renderSwitch('grouped')
    const group = container.querySelector('[role="radiogroup"]')!

    fireEvent.keyDown(group, { key: 'a' })

    expect(view()).toBe('grouped')
  })
})
