import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { enableGridNavigation } from './gridNavigation'

function buildGrid(): { table: HTMLTableElement; cleanup: () => void } {
  document.body.innerHTML = `
    <table class="data-table">
      <thead><tr><th>Name</th><th>Actions</th></tr></thead>
      <tbody>
        <tr><td>Alpha</td><td><button type="button">Edit</button></td></tr>
        <tr><td>Beta</td><td><button type="button">Edit</button></td></tr>
      </tbody>
    </table>`
  const table = document.querySelector('table') as HTMLTableElement
  const cleanup = enableGridNavigation(table)

  return { table, cleanup }
}

function key(el: Element, k: string, init: KeyboardEventInit = {}): void {
  el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, ...init }))
}

let grid: { table: HTMLTableElement; cleanup: () => void }

beforeEach(() => {
  grid = buildGrid()
})
afterEach(() => {
  grid.cleanup()
  document.body.innerHTML = ''
})

describe('enableGridNavigation', () => {
  it('sets grid roles and a single roving tab stop', () => {
    expect(grid.table.getAttribute('role')).toBe('grid')
    expect(grid.table.querySelectorAll('[role="row"]').length).toBe(3)
    expect(grid.table.querySelector('[role="columnheader"]')).not.toBeNull()
    expect(grid.table.querySelector('[role="gridcell"]')).not.toBeNull()
    expect(grid.table.querySelectorAll('[tabindex="0"]').length).toBe(1)
  })

  it('takes cell controls out of the tab order (single grid tab stop)', () => {
    expect(grid.table.querySelector('button')?.tabIndex).toBe(-1)
  })

  it('moves focus with the arrow keys and rolls the tab stop', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'ArrowDown')
    const a = document.activeElement as HTMLElement
    expect(a.textContent).toBe('Alpha')
    expect(a.tabIndex).toBe(0)

    key(a, 'ArrowRight')
    expect((document.activeElement as HTMLElement).closest('td')?.querySelector('button')).not.toBeNull()

    key(document.activeElement!, 'ArrowDown')
    expect((document.activeElement as HTMLElement).closest('tr')?.querySelector('td')?.textContent).toBe('Beta')
  })

  it('clamps at the grid edges', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'ArrowUp')
    expect(document.activeElement).toBe(firstHeader)
    key(firstHeader, 'ArrowLeft')
    expect(document.activeElement).toBe(firstHeader)
  })

  it('highlights the row of the current cell and follows arrow movement', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'ArrowDown')
    const alphaRow = (document.activeElement as HTMLElement).closest('tr')
    expect(alphaRow?.classList.contains('is-current-row')).toBe(true)

    key(document.activeElement!, 'ArrowDown')
    const betaRow = (document.activeElement as HTMLElement).closest('tr')
    expect(betaRow?.classList.contains('is-current-row')).toBe(true)
    // The highlight moved off the previous row — only one current row at a time.
    expect(grid.table.querySelectorAll('tr.is-current-row').length).toBe(1)
  })

  it('Enter moves focus into the cell control, Escape returns to the cell', () => {
    const actionsCell = grid.table.querySelectorAll('tbody td')[1] as HTMLElement
    actionsCell.focus()
    key(actionsCell, 'Enter')
    expect(document.activeElement?.tagName).toBe('BUTTON')

    key(document.activeElement!, 'Escape')
    expect(document.activeElement).toBe(actionsCell)
  })
})
