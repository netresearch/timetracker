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

  it('skips a .row-error row in vertical nav, keeping the column', () => {
    grid.cleanup()
    document.body.innerHTML = `
      <table class="data-table"><tbody>
        <tr><td data-col-key="a">A1</td><td data-col-key="b">B1</td></tr>
        <tr class="row-error"><td colspan="2">save failed</td></tr>
        <tr><td data-col-key="a">A2</td><td data-col-key="b">B2</td></tr>
      </tbody></table>`
    const table = document.querySelector('table') as HTMLTableElement
    const cleanup = enableGridNavigation(table)
    const b1 = table.tBodies[0]!.rows[0]!.cells[1]!
    b1.focus()
    key(b1, 'ArrowDown')
    // Lands on B2 (same column), NOT the 1-cell error row.
    expect(document.activeElement).toBe(table.tBodies[0]!.rows[2]!.cells[1])
    key(document.activeElement!, 'ArrowUp')
    expect(document.activeElement).toBe(b1)
    cleanup()
  })

  it('does not move onto a trailing .row-error row at the bottom edge', () => {
    grid.cleanup()
    document.body.innerHTML = `
      <table class="data-table"><tbody>
        <tr><td data-col-key="a">A1</td></tr>
        <tr class="row-error"><td>save failed</td></tr>
      </tbody></table>`
    const table = document.querySelector('table') as HTMLTableElement
    const cleanup = enableGridNavigation(table)
    const a1 = table.tBodies[0]!.rows[0]!.cells[0]!
    a1.focus()
    key(a1, 'ArrowDown') // only an error row below → stay put, don't clamp onto it
    expect(document.activeElement).toBe(a1)
    cleanup()
  })

  it('Ctrl+C writes the focused cell text; Ctrl+V reads the clipboard to seed the editor', async () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><tbody><tr><td data-col-key="a" data-row-id="1">Gamma</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const onActivate = vi.fn(() => true)
    const cleanup = enableGridNavigation(table, { onActivate })
    const cell = table.querySelector('td') as HTMLElement
    cell.focus()

    // A focused, non-editable cell never receives copy/paste events — the grid drives
    // them off the keystroke through the async clipboard API. Mock it here.
    const writeText = vi.fn(() => Promise.resolve())
    const readText = vi.fn(() => Promise.resolve('hello world'))
    const original = Object.getOwnPropertyDescriptor(navigator, 'clipboard')
    Object.defineProperty(navigator, 'clipboard', { value: { writeText, readText }, configurable: true })

    key(cell, 'c', { ctrlKey: true })
    expect(writeText).toHaveBeenCalledWith('Gamma')

    key(cell, 'v', { ctrlKey: true })
    await Promise.resolve()
    await Promise.resolve()
    expect(onActivate).toHaveBeenCalledWith(cell, 'type', 'hello world')

    if (original) {
      Object.defineProperty(navigator, 'clipboard', original)
    } else {
      Reflect.deleteProperty(navigator, 'clipboard')
    }
    cleanup()
  })

  it('Ctrl+C with an active text selection copies natively, not the whole cell', () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><tbody><tr><td data-col-key="a">Gamma</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const cleanup = enableGridNavigation(table)
    const cell = table.querySelector('td') as HTMLElement
    cell.focus()

    const writeText = vi.fn(() => Promise.resolve())
    const original = Object.getOwnPropertyDescriptor(navigator, 'clipboard')
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const getSelection = vi.spyOn(window, 'getSelection').mockReturnValue({ toString: () => 'amm' } as Selection)

    key(cell, 'c', { ctrlKey: true })
    expect(writeText).not.toHaveBeenCalled() // a real selection → native copy, not synthesized

    getSelection.mockRestore()
    if (original) {
      Object.defineProperty(navigator, 'clipboard', original)
    } else {
      Reflect.deleteProperty(navigator, 'clipboard')
    }
    cleanup()
  })

  it('PageDown jumps down a page, clamped to the last row', () => {
    const firstHeader = grid.table.querySelector('th') as HTMLElement
    firstHeader.focus()
    key(firstHeader, 'PageDown')
    expect((document.activeElement as HTMLElement).closest('tr')?.querySelector('td')?.textContent).toBe('Beta')
  })

  it('PageUp on the top row crosses to the previous page (onPageEdge) and lands on the last row', () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><thead><tr><th>N</th></tr></thead><tbody><tr><td>Alpha</td></tr><tr><td>Beta</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const onPageEdge = vi.fn(() => true)
    const cleanup = enableGridNavigation(table, { onPageEdge })
    const firstCell = table.querySelector('tbody td') as HTMLElement
    firstCell.focus()
    key(firstCell, 'PageUp')
    expect(onPageEdge).toHaveBeenCalledWith('prev')
    expect(document.activeElement?.textContent).toBe('Beta') // landed on the last row
    cleanup()
  })

  it('PageDown on the bottom row crosses to the next page and lands on the first row', () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><thead><tr><th>N</th></tr></thead><tbody><tr><td>Alpha</td></tr><tr><td>Beta</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const onPageEdge = vi.fn(() => true)
    const cleanup = enableGridNavigation(table, { onPageEdge })
    const lastCell = table.querySelectorAll('tbody td')[1] as HTMLElement
    lastCell.focus()
    key(lastCell, 'PageDown')
    expect(onPageEdge).toHaveBeenCalledWith('next')
    expect(document.activeElement?.textContent).toBe('Alpha') // landed on the first row
    cleanup()
  })

  it('stays within the page when onPageEdge declines (returns false)', () => {
    grid.cleanup()
    document.body.innerHTML = '<table class="data-table"><thead><tr><th>N</th></tr></thead><tbody><tr><td>Alpha</td></tr><tr><td>Beta</td></tr></tbody></table>'
    const table = document.querySelector('table') as HTMLTableElement
    const onPageEdge = vi.fn(() => false) // e.g. already at the first page
    const cleanup = enableGridNavigation(table, { onPageEdge })
    const firstCell = table.querySelector('tbody td') as HTMLElement
    firstCell.focus()
    key(firstCell, 'PageUp')
    expect(onPageEdge).toHaveBeenCalledWith('prev')
    // No page change → the normal within-page PageUp runs, clamping to the top
    // (the header row); it must NOT have crossed to a sibling page's last row.
    expect(document.activeElement?.textContent).toBe('N')
    cleanup()
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

  it('arrow nav follows the focused cell after a row is inserted above it (no stale-index jump)', () => {
    const betaCell = grid.table.querySelectorAll('tbody td')[2] as HTMLElement // Beta's name cell
    betaCell.focus()
    // Insert a row directly above Beta — mimics a save-error row appearing under
    // the row above, which shifts Beta's rowIndex without a gridNav re-sync.
    const inserted = document.createElement('tr')
    inserted.innerHTML = '<td>Err</td><td></td>'
    betaCell.closest('tr')!.before(inserted)
    // ArrowUp must land on the just-inserted neighbour (live position), not jump
    // to where Beta used to be relative to the stale tracked coords.
    key(betaCell, 'ArrowUp')
    expect(document.activeElement?.textContent).toBe('Err')
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

  it('Space toggles the row selection (onRowSelectToggle) and skips the default', () => {
    document.body.innerHTML = `
      <table class="data-table">
        <thead><tr><th>Sel</th><th>Name</th></tr></thead>
        <tbody><tr><td data-row-id="1"><input type="checkbox" /></td><td data-row-id="1">Alpha</td></tr></tbody>
      </table>`
    const table = document.querySelector('table') as HTMLTableElement
    const onRowSelectToggle = vi.fn(() => true)
    const cleanup = enableGridNavigation(table, { onRowSelectToggle })
    const nameCell = table.querySelectorAll('tbody td')[1] as HTMLElement
    nameCell.focus()
    const ev = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true })
    nameCell.dispatchEvent(ev)

    expect(onRowSelectToggle).toHaveBeenCalledOnce()
    expect(ev.defaultPrevented).toBe(true)
    // The default Space behaviour (focusing a control) is skipped — focus stays.
    expect(document.activeElement).toBe(nameCell)
    cleanup()
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
