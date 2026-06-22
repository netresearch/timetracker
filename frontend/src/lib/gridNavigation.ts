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

/** How a cell edit was triggered: Enter/F2 open an empty editor; 'type' opens
 *  one seeded with the printable character the user pressed. */
export type ActivateKey = 'Enter' | 'F2' | 'type'

/** Imperative roving handle the grid hands to an inline-edit owner so it can
 *  move the active cell through the SAME `setActive()` the arrow keys use —
 *  keeping the grid the single writer of the roving tabindex + aria-current. */
export interface GridMoveHandle {
  /** Move the active cell one step (clamped at the edges) and focus it. */
  move: (direction: 'up' | 'down' | 'left' | 'right') => void
  /** Re-focus the current active cell (e.g. after an editor is dismissed). */
  focusActive: () => void
}

export interface GridNavOptions {
  /** Reactive row data. Reading it subscribes the grid to re-sync (roles,
   *  roving tabindex, focus restoration) whenever the rendered rows change. */
  items?: Accessor<readonly unknown[]>
  /** Read-only data table → role=grid with aria-readonly (no cell editing). */
  readonly?: boolean
  /** Called when navigating off an edge (currently 'up' off the header row). */
  onExit?: (direction: 'up' | 'down' | 'left' | 'right') => void
  /** Begin editing the focused cell. Called on Enter/F2 and on a printable key
   *  (key='type', `initial` = the character). Return true if an editor was
   *  opened so the grid suppresses its default "focus first control". */
  onActivate?: (cell: Cell, key: ActivateKey, initial?: string) => boolean
  /** Receives the roving handle on setup and `null` on disposal, so an
   *  inline-edit owner can move the active cell after committing an edit. */
  moveRef?: (handle: GridMoveHandle | null) => void
  /** Toggle the focused cell's row selection. Called on Space (from any cell);
   *  return true if it handled the key, so the grid skips its default Space
   *  behaviour (focus a control / seed an editor). Lets a selectable grid be
   *  ticked with a single keystroke from anywhere in the row. */
  onRowSelectToggle?: (cell: Cell) => boolean
  /** Page the data at a vertical edge: PageUp on the top data row asks for the
   *  'prev' page, PageDown on the bottom row for 'next'. Return true if the page
   *  changed (the grid then lands on the last/first row of the new page), so an
   *  adjacent page is one keystroke away without scrolling the cursor across the
   *  whole table. Return false (e.g. already at the first/last page) to fall
   *  back to the normal within-page PageUp/Down. */
  onPageEdge?: (direction: 'prev' | 'next') => boolean
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

  // Height of the viewport the grid actually scrolls within — the nearest
  // vertically-scrollable ancestor, else the window. NOT table.clientHeight:
  // when the table isn't height-constrained (it scrolls with the document) that
  // equals the full content height, so PageUp/Down would jump to the list ends
  // instead of moving one screenful of rows.
  function viewportHeight(): number {
    for (let el = table.parentElement; el !== null; el = el.parentElement) {
      const overflowY = getComputedStyle(el).overflowY
      if ((overflowY === 'auto' || overflowY === 'scroll') && el.scrollHeight > el.clientHeight) {
        return el.clientHeight
      }
    }

    return window.innerHeight
  }

  function pageRows(): number {
    const sample = table.tBodies[0]?.rows[0]
    const rowHeight = sample?.getBoundingClientRect().height ?? 0
    // Unmeasurable row height (no rows / detached / jsdom) → sane default. Once
    // measurable, page by the visible rows clamped to ≥1 — NOT `visible ||
    // FALLBACK`, which would jump a near-empty viewport (visible 0) to 10 rows.
    if (rowHeight <= 0) {
      return FALLBACK_PAGE_ROWS
    }

    return Math.max(1, Math.floor(viewportHeight() / rowHeight) - 1)
  }

  function position(cell: Cell): [number, number] | null {
    const row = cell.parentElement
    if (!(row instanceof HTMLTableRowElement)) {
      return null
    }

    return [row.rowIndex, cell.cellIndex] // standard DOM indices: O(1), no allocation
  }

  function cellAt(r: number, c: number): Cell | undefined {
    const all = table.rows
    const row = all[Math.max(0, Math.min(all.length - 1, r))]
    if (!row) {
      return undefined
    }
    const cells = row.cells

    return cells[Math.max(0, Math.min(cells.length - 1, c))]
  }

  function setActive(cell: Cell, focus = true): void {
    // Hot path — runs on every arrow keystroke. Flip only the single current
    // roving cell and aria-current row (the bulk reset to tabindex=-1 happens
    // once per render in sync()), so arrow nav stays O(1) on large grids.
    const prevCell = table.querySelector<Cell>('th[tabindex="0"], td[tabindex="0"]')
    if (prevCell !== null && prevCell !== cell) {
      prevCell.tabIndex = -1
    }
    cell.tabIndex = 0
    const pos = position(cell)
    if (pos !== null) {
      active = pos
    }
    activeCellEl = cell
    const prevRow = table.querySelector('tr[aria-current="true"]')
    if (prevRow !== null && prevRow !== cell.parentElement) {
      prevRow.removeAttribute('aria-current')
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

  // The active cell's CURRENT position: its live DOM index while it's still
  // attached — so a row inserted/removed without a gridNav re-sync (e.g. a
  // save-error row appearing beneath another row) never leaves the tracked
  // coords stale and jumps the cursor — falling back to the tracked `active`
  // only when a re-render has detached the element (sync() then restores to the
  // same logical position). Single source of truth for every nav path.
  function currentPos(): [number, number] {
    if (activeCellEl !== null && table.contains(activeCellEl)) {
      const pos = position(activeCellEl)
      if (pos !== null) {
        return pos
      }
    }

    return active
  }

  // The data rows of the first <tbody>, excluding any non-data rows (e.g. a
  // `.row-error` row rendered beneath an entry) so the top/bottom-edge tests and
  // the page-edge landing target track real entries, not error rows.
  function dataRows(): HTMLTableRowElement[] {
    const body = table.tBodies[0]

    return body ? Array.from(body.rows).filter((row) => !row.classList.contains('row-error')) : []
  }

  // After a page change (onPageEdge), land on the last data row (came up from
  // the top via PageUp → continue upward) or the first (came down via PageDown),
  // keeping the column. The new page's rows are already in the DOM by here
  // (Solid applies the row update synchronously when the page signal changes).
  function focusPageLanding(edge: 'first' | 'last', column: number): void {
    const rows = dataRows()
    const target = edge === 'first' ? rows[0] : rows[rows.length - 1]
    if (target === undefined) {
      return
    }
    const cell = target.cells[Math.max(0, Math.min(target.cells.length - 1, column))]
    if (cell) {
      setActive(cell)
    }
  }

  // The next row index in a vertical direction, skipping non-data (.row-error)
  // rows — so ArrowUp/Down (and an editor's commit-move) step entry-to-entry and
  // never land on a 1-cell error row, which would collapse the cursor's column to 0.
  function verticalStep(fromRow: number, dir: -1 | 1): number {
    let next = fromRow + dir
    while (table.rows[next]?.classList.contains('row-error')) {
      next += dir
    }
    // Ran off the end (only error rows that way) → don't move, so we never clamp
    // back onto a trailing error row and collapse the column.
    if (!table.rows[next]) {
      return fromRow
    }

    return next
  }

  // Move the active cell one step, reusing focusAt's clamping. Unlike the
  // ArrowUp handler this never calls onExit — an inline editor committing with
  // Enter on the top data row should stay in the grid, not leave it.
  function focusRelative(direction: 'up' | 'down' | 'left' | 'right'): void {
    const [r, c] = currentPos()
    if (direction === 'up' || direction === 'down') {
      focusAt(verticalStep(r, direction === 'up' ? -1 : 1), c)
    } else {
      focusAt(r, c + (direction === 'left' ? -1 : 1))
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
        cell.tabIndex = -1 // bulk-reset the roving stop here (render-time); setActive then sets the one active cell
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
    // control, e.g. an inline editor's caret). Tab past the edge drops back to
    // the cell so it leaves the grid.
    if (document.activeElement !== cell) {
      // An inline editor owns Escape/Tab (commit/cancel/move) for its cell, so
      // the grid yields both keys while that cell is being edited.
      if (cell.hasAttribute('data-inline-editing')) {
        return
      }
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

    // Use the active cell's live position (see currentPos) so a row inserted/
    // removed without a re-sync can't leave the coords stale and jump the cursor.
    const [r, c] = currentPos()
    const lastRow = table.rows.length - 1 // live count, no per-keystroke allocation

    // Clipboard from a focused cell (non-edit mode): Ctrl/Cmd+C copies the cell's
    // text; Ctrl/Cmd+V opens the editor seeded with the clipboard text.
    if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey) {
      const lower = event.key.toLowerCase()
      if (lower === 'c') {
        // A real text selection copies natively (the browser's own copy of the
        // selection) — only synthesize a copy for a bare, unselected focused cell.
        if ((window.getSelection()?.toString() ?? '') !== '') {
          return
        }
        event.preventDefault()
        copyCellText(cell)

        return
      }
      if (lower === 'v' && options.onActivate !== undefined) {
        event.preventDefault()
        void pasteIntoCell(cell)

        return
      }
    }

    switch (event.key) {
      case 'ArrowRight':
        focusAt(r, c + 1)
        break
      case 'ArrowLeft':
        focusAt(r, c - 1)
        break
      case 'ArrowDown':
        focusAt(verticalStep(r, 1), c)
        break
      case 'ArrowUp':
        if (r === 0 && options.onExit) {
          options.onExit('up')
        } else {
          focusAt(verticalStep(r, -1), c)
        }
        break
      case 'PageDown': {
        const rows = dataRows()
        // On the bottom data row, PageDown crosses to the next page (landing on
        // its first row); otherwise it moves a viewport of rows within the page.
        // `cell` is the focused cell (always set here), so its row is reliable —
        // and never undefined-matches an empty dataRows().
        if (cell.parentElement === rows[rows.length - 1] && options.onPageEdge?.('next')) {
          focusPageLanding('first', c)
        } else {
          focusAt(r + pageRows(), c)
        }
        break
      }
      case 'PageUp': {
        // On the top data row, PageUp crosses to the previous page (landing on
        // its last row, so you keep moving upward); otherwise within the page.
        if (cell.parentElement === dataRows()[0] && options.onPageEdge?.('prev')) {
          focusPageLanding('last', c)
        } else {
          focusAt(r - pageRows(), c)
        }
        break
      }
      case 'Home':
        focusAt(event.ctrlKey ? 0 : r, 0)
        break
      case 'End':
        focusAt(event.ctrlKey ? lastRow : r, Number.MAX_SAFE_INTEGER)
        break
      case 'Enter':
      case 'F2': {
        // Offer the cell to an inline-edit owner first; if it opens an editor it
        // returns true and we stop here. Otherwise drop into the cell's first
        // control (APG grid) so action cells keep working unchanged.
        if (options.onActivate?.(cell, event.key)) {
          break
        }
        cell.querySelector<HTMLElement>(INTERACTIVE)?.focus()
        break
      }
      case ' ': {
        // On a selectable grid, Space ticks/unticks the row (single keystroke,
        // from any cell) — this takes precedence over the default below.
        if (options.onRowSelectToggle?.(cell)) {
          break
        }
        // Otherwise Space drops into the cell's control (APG) — or, on a display
        // cell with no control, opens the inline editor on its current value (no
        // seeded space, which would read as accidental input). Either way it
        // activates the cell instead of scrolling the page.
        const control = cell.querySelector<HTMLElement>(INTERACTIVE)
        if (control) {
          control.focus()
        } else {
          options.onActivate?.(cell, 'type')
        }
        break
      }
      default: {
        // A printable character begins editing seeded with that character. If no
        // editor takes it, fall through unhandled (no preventDefault).
        if (
          event.key.length === 1 &&
          !event.ctrlKey &&
          !event.metaKey &&
          !event.altKey &&
          options.onActivate?.(cell, 'type', event.key)
        ) {
          break
        }

        return
      }
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

  // Spreadsheet clipboard from a FOCUSED CELL (non-edit mode). The copy/paste DOM
  // events only fire on an editable element or a text selection, so a focused cell
  // never receives them — Ctrl/Cmd+C/V are driven from the keystroke (see onKeydown)
  // via the async clipboard API, with an execCommand fallback for copy in non-secure
  // contexts. In edit mode the focused input handles copy/paste natively.
  function copyCellText(cell: Cell): void {
    const text = (cell.textContent ?? '').trim()
    if (navigator.clipboard?.writeText !== undefined) {
      void navigator.clipboard.writeText(text)

      return
    }
    const area = document.createElement('textarea')
    area.value = text
    area.style.cssText = 'position:fixed;top:0;opacity:0'
    document.body.appendChild(area)
    area.select()
    try { document.execCommand('copy') } catch { /* best effort */ }
    area.remove()
    cell.focus()
  }

  async function pasteIntoCell(cell: Cell): Promise<void> {
    if (navigator.clipboard?.readText === undefined) {
      return // no async clipboard (non-secure context) → can't read the clipboard
    }
    try {
      const text = await navigator.clipboard.readText()
      if (text !== '') {
        options.onActivate?.(cell, 'type', text)
      }
    } catch { /* permission denied / empty clipboard */ }
  }

  table.addEventListener('keydown', onKeydown)
  table.addEventListener('focusin', onFocusin)

  // Hand the inline-edit owner a roving handle so it can move the active cell
  // (after committing an edit) through setActive — the single writer of the
  // roving tabindex + aria-current.
  options.moveRef?.({
    move: focusRelative,
    focusActive: () => {
      const cell = cellAt(active[0], active[1])
      if (cell) {
        setActive(cell)
      }
    },
  })

  return {
    sync,
    dispose: () => {
      table.removeEventListener('keydown', onKeydown)
      table.removeEventListener('focusin', onFocusin)
      options.moveRef?.(null)
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
