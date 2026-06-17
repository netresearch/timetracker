import { fireEvent, render } from '@solidjs/testing-library'
import { createMemo, createSignal, For } from 'solid-js'
import { describe, expect, it } from 'vitest'

import { gridNav } from './gridNavigation'

void gridNav

// Integration test for the keyboard page-edge crossing: it relies on Solid
// applying the row update synchronously when the page signal changes, so that
// the grid can land focus on the new page's row within the same keystroke.
function PagedGrid() {
  const PAGES = [
    [{ id: 1, name: 'P0-A' }, { id: 2, name: 'P0-B' }],
    [{ id: 3, name: 'P1-A' }, { id: 4, name: 'P1-B' }],
  ]
  const [page, setPage] = createSignal(1)
  const rows = createMemo(() => PAGES[page()]!)

  return (
    <table
      class="data-table"
      use:gridNav={{
        items: rows,
        onPageEdge: (direction) => {
          const target = direction === 'prev' ? page() - 1 : page() + 1
          if (target < 0 || target >= PAGES.length) {
            return false
          }
          setPage(target)

          return true
        },
      }}
    >
      <thead><tr><th>Name</th></tr></thead>
      <tbody>
        <For each={rows()}>{(row) => <tr><td>{row.name}</td></tr>}</For>
      </tbody>
    </table>
  )
}

describe('gridNav keyboard pagination (reactive)', () => {
  it('PageUp on the top row of page 1 lands on the last row of page 0', async () => {
    const { container, unmount } = render(() => <PagedGrid />)
    const topCell = container.querySelector('tbody td') as HTMLElement
    expect(topCell.textContent).toBe('P1-A') // page 1, first row
    topCell.focus()

    fireEvent.keyDown(topCell, { key: 'PageUp' })

    // Crossed to page 0 and landed on its LAST row (continue-upward).
    expect(document.activeElement?.textContent).toBe('P0-B')
    unmount()
  })

  it('PageDown on the bottom row of page 0 lands on the first row of page 1', async () => {
    const { container, unmount } = render(() => <PagedGrid />)
    // Move to page 0 first via PageUp from the top.
    const topCell = container.querySelector('tbody td') as HTMLElement
    topCell.focus()
    fireEvent.keyDown(topCell, { key: 'PageUp' }) // now on P0-B (page 0, last row)
    expect(document.activeElement?.textContent).toBe('P0-B')

    fireEvent.keyDown(document.activeElement!, { key: 'PageDown' })

    expect(document.activeElement?.textContent).toBe('P1-A') // page 1, first row
    unmount()
  })
})
