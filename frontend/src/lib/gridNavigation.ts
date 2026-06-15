import { createEffect, onCleanup, type Accessor } from 'solid-js'

// Keyboard navigation for `.data-table` grids, following the WAI-ARIA APG
// "Grid" pattern. Used as a SolidJS directive (`use:gridNav`) so its listeners
// and state are tied to the table element's lifetime (correct disposal when a
// <Show>/<Suspense> swaps the table). The roving tabindex and current row are
// derived from a tracked active cell and re-applied reactively when the row
// data changes — so focus and the highlight survive filtering/sorting/refetch
// instead of being wiped by the <For> reconcile.
//
// The grid is a single Tab stop: Arrow keys move between cells, Home/End and
// Ctrl+Home/End jump within the row / whole grid, PageUp/Down move a viewport of
// rows, Enter/F2 moves focus into a cell's control and Tab/Shift+Tab move
// between several controls in a cell, Escape returns to the cell. ArrowUp off
// the header row calls onExit('up') so the page can hand focus elsewhere. Full
// grid semantics are exposed: role=grid (+ aria-readonly for read-only tables),
// aria-rowcount/colcount, aria-rowindex/colindex, aria-current on the focused row.

type Cell = HTMLTableCellElement

const INTERACTIVE = 'button, a[href], input, select, textarea'
const FALLBACK_PAGE_ROWS = 10

export interface GridNavOptions {
  /** Reactive row data. Reading it subscribes the grid to re-sync (roles,
   *  roving tabindex, focus restoration) whenever the rendered rows change. */
  items?: Accessor<readonly unknown[]>
  /** Read-only data table → role=grid with aria-readonly (no cell editing). */
  readonly?: boolean
  /** Called when navigating off an edge (currently 'up' off the header row). */
  onExit?: (direction: 'up' | 'down' | 'left' | 'right') => void
}

interface GridController {
  /** Re-apply ARIA + roving tabindex and restore the active cell/focus. */
  sync: () => void
  /** Remove listeners. */
  dispose: () => void
}

function setupGridNav(table: HTMLTableElement, options: GridNavOptions): GridController {
  let active: [number, number] = [0, 0]
  // The element we last focused, so a re-render that removes it is detectable.
  let activeCellEl: Cell | null = null

  const rows = (): HTMLTableRowElement[] => Array.from(table.rows)
  const cellsOf = (row: HTMLTableRowElement): Cell[] => Array.from(row.cells)

  function pageRows(): number {
    const sample = table.tBodies[0]?.rows[0]
    const rowHeight = sample?.getBoundingClientRect().height ?? 0
    const visible = rowHeight > 0 ? Math.floor(table.clientHeight / rowHeight) - 1 : 0

    return Math.max(1, visible || FALLBACK_PAGE_ROWS)
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

  function cellAt(r: number, c: number): Cell | undefined {
    const all = rows()
    const row = all[Math.max(0, Math.min(all.length - 1, r))]
    if (!row) {
      return undefined
    }
    const cells = cellsOf(row)

    return cells[Math.max(0, Math.min(cells.length - 1, c))]
  }

  function setActive(cell: Cell, focus = true): void {
    for (const c of table.querySelectorAll<Cell>('th, td')) {
      c.tabIndex = -1
    }
    cell.tabIndex = 0
    const pos = position(cell)
    if (pos !== null) {
      active = pos
    }
    activeCellEl = cell
    for (const marked of table.querySelectorAll('tr[aria-current="true"]')) {
      marked.removeAttribute('aria-current')
    }
    cell.parentElement?.setAttribute('aria-current', 'true')
    if (focus) {
      cell.focus()
    }
  }

  function focusAt(r: number, c: number): void {
    const cell = cellAt(r, c)
    if (cell) {
      setActive(cell)
    }
  }

  function sync(): void {
    table.setAttribute('role', 'grid')
    if (options.readonly) {
      table.setAttribute('aria-readonly', 'true')
    }
    // Advertise that this grid joins the page's arrow-key focus chain — i.e. it
    // can be both entered and left with the arrow keys — only when it has an
    // upward exit bridge. Grids without one (e.g. a read-only table whose
    // surrounding form owns the arrow keys) stay Tab-only, so the page never
    // lets you arrow *in* somewhere you can't arrow back *out* of.
    if (options.onExit) {
      table.setAttribute('data-arrow-nav', '')
    }
    const all = rows()
    table.setAttribute('aria-rowcount', String(all.length))
    table.setAttribute('aria-colcount', String(all[0] ? cellsOf(all[0]).length : 0))

    all.forEach((row, r) => {
      row.setAttribute('role', 'row')
      row.setAttribute('aria-rowindex', String(r + 1))
      cellsOf(row).forEach((cell, c) => {
        cell.setAttribute('role', cell.tagName === 'TH' ? 'columnheader' : 'gridcell')
        cell.setAttribute('aria-colindex', String(c + 1))
        for (const control of cell.querySelectorAll<HTMLElement>(INTERACTIVE)) {
          control.tabIndex = -1
        }
      })
    })

    // Restore the roving tab stop to the tracked coordinates; re-focus only if a
    // re-render removed the focused cell and dropped focus to <body>.
    const lostFocus = activeCellEl !== null && !table.contains(activeCellEl) && document.activeElement === document.body
    const target = cellAt(active[0], active[1])
    if (target) {
      setActive(target, lostFocus)
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
    // Tab/Shift+Tab move between a cell's controls (Arrow keys are left to the
    // control, e.g. a future inline editor's caret). Tab past the edge drops
    // back to the cell so it leaves the grid.
    if (document.activeElement !== cell) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setActive(cell)

        return
      }
      if (event.key === 'Tab') {
        const controls = Array.from(cell.querySelectorAll<HTMLElement>(INTERACTIVE))
        const i = controls.indexOf(document.activeElement as HTMLElement)
        const next = i + (event.shiftKey ? -1 : 1)
        if (i !== -1 && next >= 0 && next < controls.length) {
          event.preventDefault()
          controls[next]!.focus()
        } else if (i !== -1) {
          setActive(cell, false)
        }
      }

      return
    }

    const [r, c] = active
    const lastRow = rows().length - 1

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
        if (r === 0 && options.onExit) {
          options.onExit('up')
        } else {
          focusAt(r - 1, c)
        }
        break
      case 'PageDown':
        focusAt(r + pageRows(), c)
        break
      case 'PageUp':
        focusAt(r - pageRows(), c)
        break
      case 'Home':
        focusAt(event.ctrlKey ? 0 : r, 0)
        break
      case 'End':
        focusAt(event.ctrlKey ? lastRow : r, Number.MAX_SAFE_INTEGER)
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

  function onFocusin(event: FocusEvent): void {
    const target = event.target
    if (target instanceof HTMLElement) {
      const cell = target.closest('th, td') as Cell | null
      if (cell !== null && table.contains(cell) && cell.tabIndex !== 0) {
        setActive(cell, false)
      }
    }
  }

  table.addEventListener('keydown', onKeydown)
  table.addEventListener('focusin', onFocusin)

  return {
    sync,
    dispose: () => {
      table.removeEventListener('keydown', onKeydown)
      table.removeEventListener('focusin', onFocusin)
    },
  }
}

/** SolidJS directive: `<table use:gridNav={{ items, readonly, onExit }}>`. */
export function gridNav(table: HTMLTableElement, value: Accessor<GridNavOptions>): void {
  const options = value()
  const controller = setupGridNav(table, options)
  // Initial sync + reactive re-sync whenever the row data changes.
  createEffect(() => {
    options.items?.()
    controller.sync()
  })
  onCleanup(controller.dispose)
}

/** Imperative form for non-reactive callers (unit tests). Syncs once. */
export function enableGridNavigation(table: HTMLTableElement, options: GridNavOptions = {}): () => void {
  const controller = setupGridNav(table, options)
  controller.sync()

  return controller.dispose
}

declare module 'solid-js' {
  // eslint-disable-next-line @typescript-eslint/no-namespace -- JSX directive augmentation
  namespace JSX {
    interface Directives {
      gridNav: GridNavOptions
    }
  }
}
