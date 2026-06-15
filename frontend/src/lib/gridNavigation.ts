// Keyboard navigation for `.data-table` grids, following the WCAG APG "Data
// Grid" pattern. The grid is a single Tab stop with a roving tabindex; arrow
// keys move between cells, Enter/F2 moves focus into a cell's interactive
// control (button/link/field), and Escape returns focus to the cell. Home/End
// jump within the row, Ctrl+Home/End to the first/last cell of the grid.
//
// Pass 1 is navigation only; inline cell editing builds on top of this later.
// enableGridNavigation re-applies roles/tabindex when rows change (the tables
// are rendered from reactive <For> lists), and returns a cleanup function.

type Cell = HTMLTableCellElement

const INTERACTIVE = 'button, a[href], input, select, textarea'

export function enableGridNavigation(table: HTMLTableElement): () => void {
  table.setAttribute('role', 'grid')

  const rows = (): HTMLTableRowElement[] => Array.from(table.rows)
  const cellsOf = (row: HTMLTableRowElement): Cell[] => Array.from(row.cells)

  function applyRoles(): void {
    for (const row of rows()) {
      row.setAttribute('role', 'row')
      for (const cell of cellsOf(row)) {
        cell.setAttribute('role', cell.tagName === 'TH' ? 'columnheader' : 'gridcell')
        // Controls inside cells are reached via Enter/F2, not Tab, so they must
        // not be in the document tab order while the grid owns navigation.
        for (const control of cell.querySelectorAll<HTMLElement>(INTERACTIVE)) {
          if (!control.hasAttribute('data-grid-tabindex')) {
            control.setAttribute('data-grid-tabindex', String(control.tabIndex))
          }
          control.tabIndex = -1
        }
        if (cell.tabIndex !== 0) {
          cell.tabIndex = -1
        }
      }
    }
    // Keep exactly one cell in the tab order.
    if (table.querySelector('[role="gridcell"][tabindex="0"], [role="columnheader"][tabindex="0"]') === null) {
      const firstRow = rows()[0]
      const first = firstRow ? cellsOf(firstRow)[0] : undefined
      if (first) {
        first.tabIndex = 0
      }
    }
  }

  function setActive(cell: Cell): void {
    for (const c of table.querySelectorAll<Cell>('th, td')) {
      c.tabIndex = -1
    }
    cell.tabIndex = 0
    cell.focus()
  }

  function position(cell: Cell): [number, number] | null {
    const row = cell.parentElement
    if (!(row instanceof HTMLTableRowElement)) {
      return null
    }
    const r = rows().indexOf(row)
    const c = cellsOf(row).indexOf(cell)

    return r < 0 || c < 0 ? null : [r, c]
  }

  function focusAt(r: number, c: number): void {
    const all = rows()
    const row = all[Math.max(0, Math.min(all.length - 1, r))]
    if (!row) {
      return
    }
    const cells = cellsOf(row)
    const cell = cells[Math.max(0, Math.min(cells.length - 1, c))]
    if (cell) {
      setActive(cell)
    }
  }

  function onKeydown(event: KeyboardEvent): void {
    const target = event.target
    if (!(target instanceof HTMLElement)) {
      return
    }
    const cell = target.closest('th, td') as Cell | null
    if (cell === null || !table.contains(cell)) {
      return
    }

    // Widget mode: focus is inside a cell control. Escape returns to the cell;
    // everything else is left to the control (typing in a field, etc.).
    if (document.activeElement !== cell) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setActive(cell)
      }

      return
    }

    const pos = position(cell)
    if (pos === null) {
      return
    }
    const [r, c] = pos
    const lastRow = rows().length - 1
    const lastCol = cellsOf(rows()[r]!).length - 1

    switch (event.key) {
      case 'ArrowRight':
        focusAt(r, c + 1)
        break
      case 'ArrowLeft':
        focusAt(r, c - 1)
        break
      case 'ArrowDown':
        focusAt(r + 1, c)
        break
      case 'ArrowUp':
        focusAt(r - 1, c)
        break
      case 'Home':
        if (event.ctrlKey) {
          focusAt(0, 0)
        } else {
          focusAt(r, 0)
        }
        break
      case 'End':
        if (event.ctrlKey) {
          focusAt(lastRow, Number.MAX_SAFE_INTEGER)
        } else {
          focusAt(r, lastCol)
        }
        break
      case 'Enter':
      case 'F2': {
        const control = cell.querySelector<HTMLElement>(INTERACTIVE)
        if (control) {
          control.focus()
        }
        break
      }
      default:
        return
    }
    event.preventDefault()
  }

  // Track the active cell as focus moves so the roving tabindex follows clicks.
  function onFocusin(event: FocusEvent): void {
    const target = event.target
    if (target instanceof HTMLElement) {
      const cell = target.closest('th, td') as Cell | null
      if (cell !== null && table.contains(cell) && cell.tabIndex !== 0) {
        for (const c of table.querySelectorAll<Cell>('th, td')) {
          c.tabIndex = -1
        }
        cell.tabIndex = 0
      }
    }
  }

  applyRoles()
  const observer = new MutationObserver(() => applyRoles())
  observer.observe(table, { childList: true, subtree: true })
  table.addEventListener('keydown', onKeydown)
  table.addEventListener('focusin', onFocusin)

  return () => {
    observer.disconnect()
    table.removeEventListener('keydown', onKeydown)
    table.removeEventListener('focusin', onFocusin)
  }
}
