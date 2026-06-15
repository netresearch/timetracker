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
const PAGE_ROWS = 10

interface GridNavigationOptions {
  /** Called when ArrowUp is pressed on the top (header) row, so the caller can
   *  hand focus to whatever sits above the grid (e.g. a search field). */
  onExitTop?: () => void
}

export function enableGridNavigation(table: HTMLTableElement, options: GridNavigationOptions = {}): () => void {
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

  // Highlight the row holding the current cell — the "current row" affordance
  // the ExtJS grid provides (Help: "the focused entry has a highlighted row").
  function markCurrentRow(cell: Cell): void {
    for (const marked of table.querySelectorAll('tr.is-current-row')) {
      marked.classList.remove('is-current-row')
    }
    if (cell.parentElement instanceof HTMLTableRowElement) {
      cell.parentElement.classList.add('is-current-row')
    }
  }

  function setActive(cell: Cell): void {
    for (const c of table.querySelectorAll<Cell>('th, td')) {
      c.tabIndex = -1
    }
    cell.tabIndex = 0
    markCurrentRow(cell)
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
    // when a cell has several controls (e.g. Edit + Delete), Arrow/Tab move
    // between them (they're out of the document tab order); everything else is
    // left to the control (typing in a field, etc.).
    if (document.activeElement !== cell) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setActive(cell)

        return
      }
      const controls = Array.from(cell.querySelectorAll<HTMLElement>(INTERACTIVE))
      const index = controls.indexOf(document.activeElement as HTMLElement)
      const forward = event.key === 'ArrowRight' || (event.key === 'Tab' && !event.shiftKey)
      const backward = event.key === 'ArrowLeft' || (event.key === 'Tab' && event.shiftKey)
      if (index !== -1 && (forward || backward)) {
        const next = index + (forward ? 1 : -1)
        if (next >= 0 && next < controls.length) {
          event.preventDefault()
          controls[next]!.focus()
        } else if (event.key === 'Tab') {
          // At the cell's edge: drop back to the cell so Tab leaves the grid.
          setActive(cell)
        }
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
        // Off the top row, hand focus to whatever sits above the grid.
        if (r === 0 && options.onExitTop) {
          options.onExitTop()
        } else {
          focusAt(r - 1, c)
        }
        break
      case 'PageDown':
        focusAt(r + PAGE_ROWS, c)
        break
      case 'PageUp':
        focusAt(r - PAGE_ROWS, c)
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
      if (cell !== null && table.contains(cell)) {
        if (cell.tabIndex !== 0) {
          for (const c of table.querySelectorAll<Cell>('th, td')) {
            c.tabIndex = -1
          }
          cell.tabIndex = 0
        }
        markCurrentRow(cell)
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
