import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { enableGridNavigation, type GridMoveHandle } from './gridNavigation'

function buildGrid(): { table: HTMLTableElement; cleanup: () => void } {
  document.body.innerHTML = `
    <table class="data-table">
      <thead><tr><th>Name</th><th>Actions</th></tr></thead>
      <tbody>
        <tr><td>Alpha</td><td><button type="button">Edit</button><button type="button">Delete</button></td></tr>
        <tr><td>Beta</td><td><button type="button">Edit</button><button type="button">Delete</button></td></tr>
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

  it('exposes grid row/column counts and indices', () => {
    expect(grid.table.getAttribute('aria-rowcount')).toBe('3') // 1 header + 2 body rows
    expect(grid.table.getAttribute('aria-colcount')).toBe('2')
    expect(grid.table.querySelector('tbody tr')?.getAttribute('aria-rowindex')).toBe('2')
    expect(grid.table.querySelector('tbody td')?.getAttribute('aria-colindex')).toBe('1')
  })

  it('marks a read-only grid with aria-readonly', () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><tbody><tr><td>x</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const cleanup = enableGridNavigation(table, { readonly: true })
    expect(table.getAttribute('aria-readonly')).toBe('true')
    cleanup()
  })

  it('advertises data-arrow-nav only when an arrow-exit is wired', () => {
    // No onExit → Tab-only grid, not an arrow-entry target for the page chain.
    expect(grid.table.hasAttribute('data-arrow-nav')).toBe(false)

    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><tbody><tr><td>x</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const cleanup = enableGridNavigation(table, { onExit: () => {} })
    expect(table.hasAttribute('data-arrow-nav')).toBe(true)
    cleanup()
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

  it('PageDown jumps down a page, clamped to the last row', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'PageDown')
    expect((document.activeElement as HTMLElement).closest('tr')?.querySelector('td')?.textContent).toBe('Beta')
  })

  it('calls onExit("up") when ArrowUp leaves the top row', () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><thead><tr><th>H</th></tr></thead><tbody><tr><td>A</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const onExit = vi.fn()
    const cleanup = enableGridNavigation(table, { onExit })
    const th = table.querySelector('th') as HTMLElement
    th.focus()
    key(th, 'ArrowUp')
    expect(onExit).toHaveBeenCalledWith('up')
    cleanup()
  })

  it('clamps at the grid edges', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'ArrowUp')
    expect(document.activeElement).toBe(firstHeader)
    key(firstHeader, 'ArrowLeft')
    expect(document.activeElement).toBe(firstHeader)
  })

  it('marks the current row with aria-current and follows arrow movement', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'ArrowDown')
    const alphaRow = (document.activeElement as HTMLElement).closest('tr')
    expect(alphaRow?.getAttribute('aria-current')).toBe('true')

    key(document.activeElement!, 'ArrowDown')
    const betaRow = (document.activeElement as HTMLElement).closest('tr')
    expect(betaRow?.getAttribute('aria-current')).toBe('true')
    // Only one current row at a time.
    expect(grid.table.querySelectorAll('tr[aria-current="true"]').length).toBe(1)
  })

  it('Enter enters the cell, Tab/Shift+Tab reach every control, Escape returns', () => {
    const actionsCell = grid.table.querySelectorAll('tbody td')[1] as HTMLElement
    actionsCell.focus()
    key(actionsCell, 'Enter')
    expect(document.activeElement?.textContent).toBe('Edit')

    // Both Edit and Delete must be reachable (the bug: Delete was unreachable).
    // Tab moves between a cell's controls; Arrow keys are reserved for the
    // control itself (e.g. a future inline text editor's caret).
    key(document.activeElement!, 'Tab')
    expect(document.activeElement?.textContent).toBe('Delete')
    key(document.activeElement!, 'Tab', { shiftKey: true })
    expect(document.activeElement?.textContent).toBe('Edit')

    key(document.activeElement!, 'Escape')
    expect(document.activeElement).toBe(actionsCell)
  })

  it('Space enters the cell like Enter (and does not scroll)', () => {
    const actionsCell = grid.table.querySelectorAll('tbody td')[1] as HTMLElement
    actionsCell.focus()
    const ev = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true })
    actionsCell.dispatchEvent(ev)
    expect(document.activeElement?.textContent).toBe('Edit')
    expect(ev.defaultPrevented).toBe(true) // scroll suppressed
  })

  it('Tab past the last cell control does not trap (no preventDefault)', () => {
    const actionsCell = grid.table.querySelectorAll('tbody td')[1] as HTMLElement
    actionsCell.focus()
    key(actionsCell, 'Enter') // Edit
    key(document.activeElement!, 'Tab') // Delete (last control)
    // Tabbing off the last control must let native Tab leave the grid: the cell
    // reclaims the roving tab stop and the event is NOT prevented.
    const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true })
    document.activeElement!.dispatchEvent(ev)
    expect(ev.defaultPrevented).toBe(false)
    expect(actionsCell.getAttribute('tabindex')).toBe('0')
  })
})

describe('inline-edit hooks', () => {
  function buildEditableGrid(): HTMLTableElement {
    document.body.innerHTML = `
      <table class="data-table">
        <thead><tr><th>Name</th></tr></thead>
        <tbody>
          <tr><td data-row-id="1" data-col-key="name">Alpha</td></tr>
          <tr><td data-row-id="2" data-col-key="name">Beta</td></tr>
        </tbody>
      </table>`

    return document.querySelector('table') as HTMLTableElement
  }

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('routes Enter and F2 through onActivate and suppresses the default control focus', () => {
    const table = buildEditableGrid()
    const onActivate = vi.fn(() => true)
    const cleanup = enableGridNavigation(table, { onActivate })
    const cell = table.querySelector('tbody td') as HTMLElement
    cell.focus()

    for (const k of ['Enter', 'F2'] as const) {
      const ev = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })
      cell.dispatchEvent(ev)
      expect(onActivate).toHaveBeenLastCalledWith(cell, k)
      expect(ev.defaultPrevented).toBe(true)
    }
    cleanup()
  })

  it('routes a printable key through onActivate seeded with the character', () => {
    const table = buildEditableGrid()
    const onActivate = vi.fn(() => true)
    const cleanup = enableGridNavigation(table, { onActivate })
    const cell = table.querySelector('tbody td') as HTMLElement
    cell.focus()

    const ev = new KeyboardEvent('keydown', { key: 'x', bubbles: true, cancelable: true })
    cell.dispatchEvent(ev)
    expect(onActivate).toHaveBeenCalledWith(cell, 'type', 'x')
    expect(ev.defaultPrevented).toBe(true)
    cleanup()
  })

  it('leaves a declined activation to the existing behaviour (no preventDefault)', () => {
    const table = buildEditableGrid()
    const onActivate = vi.fn(() => false)
    const cleanup = enableGridNavigation(table, { onActivate })
    const cell = table.querySelector('tbody td') as HTMLElement
    cell.focus()

    // A printable key the editor declines must fall through unhandled.
    const ev = new KeyboardEvent('keydown', { key: 'x', bubbles: true, cancelable: true })
    cell.dispatchEvent(ev)
    expect(ev.defaultPrevented).toBe(false)
    cleanup()
  })

  it('yields Escape and Tab to a cell flagged data-inline-editing', () => {
    document.body.innerHTML = `
      <table class="data-table"><tbody>
        <tr><td data-row-id="1" data-col-key="name" data-inline-editing><input type="text" /></td></tr>
      </tbody></table>`
    const table = document.querySelector('table') as HTMLTableElement
    const cleanup = enableGridNavigation(table)
    const input = table.querySelector('input') as HTMLInputElement
    input.focus()

    // Without the editor flag gridNav would consume Escape (return to cell) and
    // Tab; with it, both pass through so the editor's own handler owns them.
    for (const k of ['Escape', 'Tab'] as const) {
      const ev = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })
      input.dispatchEvent(ev)
      expect(ev.defaultPrevented).toBe(false)
      expect(document.activeElement).toBe(input)
    }
    cleanup()
  })

  it('exposes a roving move handle that keeps a single tab stop + current row', () => {
    const table = buildEditableGrid()
    let handle: GridMoveHandle | null = null
    const cleanup = enableGridNavigation(table, { moveRef: (h) => { handle = h } })
    const firstCell = table.querySelector('tbody td') as HTMLElement
    firstCell.focus()

    handle!.move('down')
    expect((document.activeElement as HTMLElement).textContent).toBe('Beta')
    expect(table.querySelectorAll('[tabindex="0"]').length).toBe(1)
    expect(table.querySelectorAll('tr[aria-current="true"]').length).toBe(1)

    handle!.focusActive()
    expect((document.activeElement as HTMLElement).textContent).toBe('Beta')

    cleanup()
    // The handle is revoked on disposal.
    expect(handle).toBeNull()
  })
})
