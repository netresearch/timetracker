import { createSignal, createUniqueId, For, Match, onCleanup, onMount, Show, Switch, type Accessor } from 'solid-js'
import { createStore, produce } from 'solid-js/store'

import type { FieldDef, FormValues, OptionLookup } from '../admin/types'
import { getEnterBehavior } from './gridEditPref'
import type { ActivateKey, GridMoveHandle } from './gridNavigation'
import { m } from '../paraglide/messages.js'

export type Row = Record<string, unknown>

// Field types that get a compact in-cell editor; everything else (multiselect,
// locked relation columns) stays modal-only.
export const INLINE_TYPES = new Set<FieldDef['type']>(['text', 'number', 'date', 'checkbox', 'select', 'multiselect'])

// Single-line editors that overlay the cell (absolutely positioned over a hidden
// "ghost" of the value, which holds the column width) so opening them can't make
// the auto-layout table re-flow the column. Checkbox and multiselect editors stay
// in flow (they aren't single-line text and size differently).
export const INLINE_OVERLAY_TYPES = new Set<FieldDef['type']>(['text', 'number', 'date'])

/** Shared option resolution for both the modal FieldControl and the inline
 *  editor, so relation/static dropdowns stay identical in either surface. */
export function fieldSelectOptions(field: FieldDef, options: OptionLookup): { value: string | number; label: string }[] {
  if (field.staticOptions) {
    return field.staticOptions.map((option) => ({ value: option.value, label: option.label() }))
  }

  return field.source ? options(field.source).map((option) => ({ value: option.id, label: option.label })) : []
}

/** A select/multiselect cell value → the list of chip values to render. A single
 *  select is one chip; "none" (0 / '' / null) is no chips. */
export function chipValues(raw: unknown): (string | number)[] {
  if (Array.isArray(raw)) {
    return raw as (string | number)[]
  }
  if (raw === undefined || raw === null || raw === '' || raw === 0) {
    return []
  }

  return [raw as string | number]
}

/** Read-only chips for a select/multiselect column in display mode, so reference
 *  columns read as chips (visually distinct from free text) in both grids. */
export function ReadonlyChips(props: Readonly<{ values: (string | number)[]; options: { value: string | number; label: string }[] }>) {
  const labelOf = (value: string | number): string => props.options.find((option) => String(option.value) === String(value))?.label ?? String(value)

  return (
    <span class="inline-tags is-readonly">
      <For each={props.values}>
        {(value) => <span class="tag"><span class="tag-label">{labelOf(value)}</span></span>}
      </For>
    </span>
  )
}

/**
 * Compact in-cell editor for inline (spreadsheet-style) editing. It owns its
 * keys: Enter commits (direction from the user's preference), Tab/Shift+Tab
 * commit and move right/left, Escape cancels; all four stopPropagation so
 * neither the grid nor the global shortcut handler sees them. Blur commits in
 * place (click-away). The value is coerced back to the field's payload type on
 * commit, matching FieldControl.
 */
export function InlineEditor(props: {
  field: FieldDef
  label: string
  initial: FormValues[string]
  seed?: string
  options: OptionLookup
  onCommit: (value: FormValues[string], direction?: 'down' | 'left' | 'right' | 'stay' | 'next') => void
  onCancel: () => void
}) {
  const isCheckbox = (): boolean => props.field.type === 'checkbox'
  const [value, setValue] = createSignal<string | boolean>(isCheckbox() ? Boolean(props.initial) : String(props.initial ?? ''))
  let control: HTMLInputElement | HTMLSelectElement | undefined
  // Stable id linking the date input to its visually-hidden format hint, so the
  // required YYYY-MM-DD format is announced (a placeholder alone is not reliable).
  const dateHintId = createUniqueId()
  // Guards against a second commit: the keydown move already committed, and the
  // editor then unmounts and blurs — the blur must not re-commit.
  let done = false

  function coerced(): FormValues[string] {
    if (isCheckbox()) {
      return value() as boolean
    }
    const raw = value() as string
    if (props.field.type === 'number' || (props.field.type === 'select' && props.field.stringValue !== true)) {
      return Number(raw)
    }

    return raw
  }

  const finish = (direction?: 'down' | 'left' | 'right' | 'stay' | 'next') => {
    if (done) {
      return
    }
    done = true
    props.onCommit(coerced(), direction)
  }
  const cancel = () => {
    if (done) {
      return
    }
    done = true
    props.onCancel()
  }

  const onKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
      event.preventDefault()
      event.stopPropagation()
      // 'next' = Enter: the controller guides to the next required field on an
      // incomplete row, else falls back to the user's stay/down preference. Tab
      // below always advances, so the user keeps an explicit move key.
      finish('next')
    } else if (event.key === 'Tab') {
      event.preventDefault()
      event.stopPropagation()
      finish(event.shiftKey ? 'left' : 'right')
    } else if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      cancel()
    }
  }

  onMount(() => {
    if (props.seed !== undefined && control instanceof HTMLInputElement) {
      setValue(props.seed)
    }
    control?.focus()
  })

  return (
    <Switch>
      <Match when={isCheckbox()}>
        <input
          ref={(el) => { control = el }}
          type="checkbox"
          class="inline-editor inline-check"
          aria-label={props.label}
          checked={value() as boolean}
          onInput={(e) => setValue(e.currentTarget.checked)}
          onKeyDown={onKeyDown}
          onBlur={() => finish()}
        />
      </Match>
      <Match when={true}>
        <input
          ref={(el) => { control = el }}
          // Dates use a text input with the ISO value, not type="date": a native
          // date control renders in the browser's locale (mm/dd/yyyy, dd.mm.yyyy),
          // which changes the format on edit. Text keeps it yyyy-mm-dd throughout.
          type={props.field.type === 'number' ? 'number' : 'text'}
          placeholder={props.field.type === 'date' ? 'YYYY-MM-DD' : undefined}
          aria-describedby={props.field.type === 'date' ? dateHintId : undefined}
          class="inline-editor"
          aria-label={props.label}
          value={value() as string}
          onInput={(e) => setValue(e.currentTarget.value)}
          onKeyDown={onKeyDown}
          onBlur={() => finish()}
        />
        <Show when={props.field.type === 'date'}>
          <span id={dateHintId} class="visually-hidden">{m.tracking_date_format_hint()}</span>
        </Show>
      </Match>
    </Switch>
  )
}

const idOf = (row: object): number => Number((row as { id?: unknown }).id)

export interface InlineGridEditConfig<R extends object> {
  /** Reactive list of the rows currently shown (keyed by a numeric `id`). */
  rows: Accessor<readonly R[]>
  /** The field backing a column key (drives the editor type), or undefined. */
  fieldFor: (colKey: string) => FieldDef | undefined
  /** Whether a column can be edited in place (vs modal-only / read-only). */
  isInlineEditable: (colKey: string) => boolean
  /** Seed a fresh draft from a row (the editable form values). */
  seedDraft: (row: R) => FormValues
  /** Persist one row's draft (POST + refetch); rejects to surface a row error. */
  saveRow: (draft: FormValues, row: R) => Promise<void>
  /** Fallback for activating a non-inline column (e.g. open a modal); return
   *  true if it handled the activation. */
  onModalActivate?: (row: R) => boolean
  /** Called right after a cell commits, so the host can derive sibling draft
   *  fields (e.g. a ticket → project/customer mapping). */
  onCommit?: (id: number, colKey: string, value: FormValues[string]) => void
  /** Called after a successful row save (e.g. a toast). */
  onSaved?: () => void
  /** Map a save error to a per-row message. Defaults to the generic load error. */
  saveErrorMessage?: (error: unknown) => string
  /** The column keys whose draft value is missing/invalid (empty array = the row
   *  is complete and may auto-save). Drives the per-cell invalid hint and gates
   *  auto-save on completeness. Omit to disable auto-save (save on leave/force). */
  invalidFields?: (draft: FormValues, row: R) => string[]
  /** Whether a row has never been persisted (e.g. a brand-new work-log row with a
   *  temporary id). A new row's seed IS its content, so "draft equals seed" is not
   *  a no-op — it must still save once complete (see #495). Omit for grids that
   *  never create in-place new rows (the default: no row is treated as new). */
  isNewRow?: (row: R) => boolean
}

/**
 * The spreadsheet-style inline-edit controller shared by the admin grid and the
 * work-log grid. gridNav stays the single roving-tabindex/ARIA owner; this owns
 * per-row drafts (keyed by `id`) and saves the whole row once focus leaves it.
 * Create it inside a component (it registers reactive state + onCleanup).
 */
export function createInlineGridEdit<R extends object>(config: InlineGridEditConfig<R>) {
  const [editCell, setEditCell] = createSignal<{ rowId: number; colKey: string } | null>(null)
  const [drafts, setDrafts] = createStore<Record<number, FormValues>>({})
  const [savingRows, setSavingRows] = createStore<Record<number, boolean>>({})
  const [rowErrors, setRowErrors] = createStore<Record<number, string>>({})
  // Per-row list of missing/invalid column keys, for the quiet (border) hint
  // shown while auto-saving — never a blocking error message.
  const [fieldHints, setFieldHints] = createStore<Record<number, string[]>>({})
  let seedChar: string | undefined
  let moveHandle: GridMoveHandle | null = null
  let tableEl: HTMLElement | undefined
  let lastFocusedRowId: number | null = null
  // Non-reactive snapshot of the row a draft was seeded from, so a pending edit
  // can still save on unmount even if the parent already cleared the rows list
  // (e.g. route/tab change) — rowById() would then return undefined.
  const originalRows = new Map<number, R>()

  const rowById = (id: number): R | undefined => originalRows.get(id) ?? config.rows().find((row) => idOf(row) === id)

  // The row merged with its pending draft, so edited-but-unsaved cells render
  // current values without touching the query cache.
  const overlayRow = (row: R): R => {
    const draft = drafts[idOf(row)]

    return draft !== undefined ? { ...row, ...draft } as R : row
  }

  const isEditing = (id: number, colKey: string): boolean => {
    const cell = editCell()

    return cell !== null && cell.rowId === id && cell.colKey === colKey
  }

  const draftValue = (id: number, colKey: string): FormValues[string] | undefined => drafts[id]?.[colKey]

  // A draft is only "dirty" when it actually differs from the row it was seeded
  // from. Entering a cell seeds a draft (so the editor + overlay work), but that
  // alone is not a change — comparing against a fresh seed keeps the dirty cues
  // (warning tint, disk button) and the save off until the user really edits.
  const rowDirty = (id: number): boolean => {
    const draft = drafts[id]
    if (draft === undefined) {
      return false
    }
    const row = rowById(id)
    return row !== undefined && JSON.stringify(draft) !== JSON.stringify(config.seedDraft(row))
  }
  const isDirty = rowDirty

  // A never-persisted row whose draft is complete must still be saved even though
  // it equals its seed — the seed IS its content (e.g. a "Continue" row pre-filled
  // from another entry). Without this, such a row reads as a clean no-op and is
  // silently discarded instead of saved (#495). An incomplete new row is NOT
  // persisted here, so an abandoned blank row is dropped quietly rather than
  // raising a validation error.
  const isNewAndComplete = (id: number, row: R): boolean => {
    if (config.isNewRow?.(row) !== true) {
      return false
    }
    const draft = drafts[id]

    return draft !== undefined
      && (config.invalidFields === undefined || config.invalidFields(draft, row).length === 0)
  }

  // Drop a row's draft + field-hints + original-row snapshot — the teardown
  // shared by save-success, modal take-over, reset, and the clean-up of an
  // unchanged draft. (rowErrors is cleared separately, only where it applies.)
  function clearDraftState(id: number): void {
    setDrafts(produce((store) => { delete store[id] }))
    setFieldHints(produce((store) => { delete store[id] }))
    originalRows.delete(id)
  }

  // Drop a draft that ended up identical to its original row (opened a cell and
  // left without changing it, or edited a value back) so the row shows no dirty
  // state and never triggers a no-op save.
  function discardIfClean(id: number): void {
    if (drafts[id] !== undefined && !rowDirty(id)) {
      clearDraftState(id)
    }
  }

  function beginEdit(id: number, colKey: string, char?: string): boolean {
    if (!config.isInlineEditable(colKey)) {
      return false
    }
    const row = rowById(id)
    if (row === undefined) {
      return false
    }
    if (drafts[id] === undefined) {
      setDrafts(id, config.seedDraft(row))
      originalRows.set(id, row)
    }
    const field = config.fieldFor(colKey)
    // Only text-like fields are seeded with the keystroke that opened them.
    seedChar = char !== undefined && (field?.type === 'text' || field?.type === 'number' || field?.type === 'date') ? char : undefined
    setRowErrors(id, '')
    setEditCell({ rowId: id, colKey })

    return true
  }

  // gridNav offers the focused cell on Enter/F2/printable; open an editor and
  // return true so the grid suppresses its default "focus first control".
  const onActivate = (cell: HTMLTableCellElement, key: ActivateKey, initial?: string): boolean => {
    const id = Number(cell.getAttribute('data-row-id'))
    const colKey = cell.getAttribute('data-col-key')
    if (!id || colKey === null) {
      return false
    }
    if (config.isInlineEditable(colKey)) {
      return beginEdit(id, colKey, key === 'type' ? initial : undefined)
    }
    // A modal-only column (multiselect / locked relation) opens the full editor
    // on Enter/F2 so it stays reachable from the keyboard.
    if (config.fieldFor(colKey) !== undefined && key !== 'type' && config.onModalActivate !== undefined) {
      const row = rowById(id)
      if (row !== undefined) {
        return config.onModalActivate(row)
      }
    }

    return false
  }

  // Tab / Shift+Tab from an editor: move horizontally to the next INLINE-EDITABLE
  // cell and open its editor — so Tab walks across the row staying in edit mode
  // (the classic-grid behaviour), skipping non-editable cells (duration, actions)
  // and stopping at the row edge. Reads document.activeElement because the grid's
  // move handle is the single writer of focus + the roving tabindex.
  function moveAndEdit(direction: 'left' | 'right'): void {
    // Track the roving tab stop (the single td[tabindex="0"] that setActive owns), NOT
    // document.activeElement: committing a text editor unmounts its <input> and drops
    // focus to <body>, so reading activeElement would see "no move" and bail at the
    // first step. The roving cell is always current, so the walk stays reliable even
    // while focus is momentarily on <body>; beginEdit's editor re-grabs focus on mount.
    const rovingCell = (): HTMLElement | null =>
      tableEl?.querySelector<HTMLElement>('td[tabindex="0"], th[tabindex="0"]') ?? null
    for (let i = 0; i < 30; i++) {
      const before = rovingCell()
      moveHandle?.move(direction)
      const cell = rovingCell()
      if (cell === null || cell === before) {
        return // clamped at the row edge — no editable cell that way
      }
      const colKey = cell.getAttribute('data-col-key')
      const id = Number(cell.getAttribute('data-row-id'))
      if (id && colKey !== null && config.isInlineEditable(colKey)) {
        beginEdit(id, colKey)

        return
      }
    }
  }

  // Enter on an INCOMPLETE row jumps to the next field that still needs input (a
  // required/invalid cell) and opens its editor — a guided fill flow. Forward-only
  // in the row's column order: it advances to the next required cell to the RIGHT of
  // the one just committed and never wraps back to an earlier cell (which would feel
  // like the cursor jumping backwards). Returns false when nothing required remains
  // to the right — Enter then follows the stay/down preference. A no-op when the
  // host provides no invalidFields.
  function moveToNextRequired(rowId: number, fromColKey: string): boolean {
    if (config.invalidFields === undefined || tableEl === undefined) {
      return false
    }
    const draft = drafts[rowId]
    const row = rowById(rowId)
    if (draft === undefined || row === undefined) {
      return false
    }
    const invalid = new Set(config.invalidFields(draft, row))
    if (invalid.size === 0) {
      return false
    }
    const colKeys = Array.from(tableEl.querySelectorAll<HTMLElement>(`td[data-row-id="${rowId}"][data-col-key]`))
      .map((td) => td.getAttribute('data-col-key'))
    const from = colKeys.indexOf(fromColKey)
    for (let next = from + 1; next < colKeys.length; next++) {
      const colKey = colKeys[next]
      if (colKey != null && invalid.has(colKey) && config.isInlineEditable(colKey)) {
        moveHandle?.focusCell(rowId, colKey)
        beginEdit(rowId, colKey)

        return true
      }
    }

    return false
  }

  function commitCell(value: FormValues[string], direction?: 'down' | 'left' | 'right' | 'stay' | 'next'): void {
    const cell = editCell()
    if (cell === null) {
      return
    }
    setDrafts(cell.rowId, cell.colKey, value)
    // Let the host react to a commit (e.g. derive sibling fields from a ticket).
    config.onCommit?.(cell.rowId, cell.colKey, value)
    seedChar = undefined
    setEditCell(null)
    // If the net result equals the original row (no real change, or a value
    // edited back), drop the draft so the row stays clean — and refreshHints
    // then sees no draft and skips the (pointless) auto-save. A complete NEW row
    // is the exception: its seed IS its content, so dropping it here would discard
    // a pre-filled "Continue" entry instead of saving it (#495).
    const committedRow = rowById(cell.rowId)
    if (committedRow === undefined || !isNewAndComplete(cell.rowId, committedRow)) {
      discardIfClean(cell.rowId)
    }
    // A Tab move (left/right) stays in the same row and opens the next cell's
    // editor; auto-saving now would refetch and remount that editor away (#481),
    // so defer the save to row-leave. Every other commit auto-saves as before.
    const staysInRowViaTab = direction === 'left' || direction === 'right'
    refreshHints(cell.rowId, !staysInRowViaTab)

    // The post-commit move (all through the grid's setActive — the single
    // roving-tabindex writer). Enter (stay/down) on an incomplete row is guided to
    // the next required field; otherwise it follows the stay/down preference. Tab
    // walks to the next editable cell.
    const { rowId, colKey } = cell
    const advance = (): void => {
      if (direction === 'left' || direction === 'right') {
        moveAndEdit(direction)

        return
      }
      // Enter ('next'): guide to the next required field on an incomplete row, else
      // fall back to the user's Enter (stay/down) preference. Click-select and the
      // plain stay/down directions keep their behaviour (no guide).
      const enter = direction === 'next'
      if (enter && moveToNextRequired(rowId, colKey)) {
        return
      }
      const effective = enter ? getEnterBehavior() : direction
      if (effective === 'down') {
        moveHandle?.move('down')
      } else if (effective === 'stay') {
        moveHandle?.focusActive()
      }
    }
    // A select/multiselect editor lives in a body-portal; Ark restores focus to its
    // (now-unmounting) trigger as it closes, which would steal focus to <body> and
    // lose the cell. Run the move AFTER that teardown so focus lands where we put it.
    const fromType = config.fieldFor(colKey)?.type
    if (fromType === 'select' || fromType === 'multiselect') {
      // Skip if the grid was torn down (route change) before the frame fires, so we
      // never drive focus into a stale/unmounted grid (moveHandle is nulled on dispose).
      requestAnimationFrame(() => {
        if (moveHandle !== null) {
          advance()
        }
      })
    } else {
      advance()
    }
  }

  // After a commit: recompute the row's invalid-field hints and, when the row is
  // complete (no invalid fields), auto-save it quietly. Saving no longer depends
  // on leaving the row. A no-op when the host provides no invalidFields.
  //
  // autoSave is suppressed for a Tab move that keeps editing the SAME row: saveRow
  // refetches, which remounts the rows and would unmount the editor Tab just opened
  // on the next cell (#481 — "lose inline-edit mode on Tab"). The row still saves
  // when focus leaves it (onTableFocusOut / row-leave) or on any non-Tab commit.
  function refreshHints(id: number, autoSave = true): void {
    if (config.invalidFields === undefined) {
      return
    }
    const draft = drafts[id]
    const row = rowById(id)
    if (draft === undefined || row === undefined) {
      return
    }
    const invalid = config.invalidFields(draft, row)
    setFieldHints(id, invalid)
    if (autoSave && invalid.length === 0) {
      void flushRow(id)
    }
  }

  function cancelCell(): void {
    const id = editCell()?.rowId
    seedChar = undefined
    setEditCell(null)
    // Cancelling the current cell leaves the draft untouched; if that draft only
    // ever held the seeded (unchanged) values, drop it so the row stays clean.
    if (id !== undefined) {
      discardIfClean(id)
    }
    moveHandle?.focusActive()
  }

  // Take over a row's pending draft (e.g. a modal opening on the same row):
  // returns a copy and drops the inline draft + open editor.
  function takeDraft(id: number): FormValues | undefined {
    const draft = drafts[id]
    if (draft === undefined) {
      return undefined
    }
    if (editCell()?.rowId === id) {
      setEditCell(null)
    }
    clearDraftState(id)

    return { ...draft }
  }

  // Discard a row's entire unsaved state and restore it to its underlying (DB)
  // values: drop the draft + field hints + any blocking row error, and close an
  // open editor on THIS row. overlayRow then falls back to the original row, so
  // every cell re-renders to its saved value reactively — no refetch (nothing
  // changed server-side). Unlike takeDraft (which hands the draft to a modal and
  // leaves rowErrors), this is the "throw away my edits" path. savingRows is left
  // untouched: never reset a row mid-save (the caller gates the UI on it).
  function resetRow(id: number): void {
    if (editCell()?.rowId === id) {
      setEditCell(null)
    }
    clearDraftState(id)
    setRowErrors(produce((store) => { delete store[id] }))
    // Forget the row as last-focused — a reset that REMOVES the row (a new row
    // dropped by the host) would otherwise leave a row-leave flush pointed at a
    // gone id (harmless via the rowById guard, but avoid the dangling reference).
    if (lastFocusedRowId === id) {
      lastFocusedRowId = null
    }
    seedChar = undefined
  }

  async function flushRow(id: number): Promise<void> {
    const draft = drafts[id]
    const row = rowById(id)
    if (draft === undefined || row === undefined || savingRows[id]) {
      return
    }
    // Nothing changed vs a fresh seed. For an EXISTING row that's a no-op — drop
    // the draft instead of re-POSTing an identical row. A complete NEW row is the
    // exception: its seed is its content, so it still needs persisting (#495).
    if (!rowDirty(id) && !isNewAndComplete(id, row)) {
      discardIfClean(id)

      return
    }
    setSavingRows(id, true)
    setRowErrors(id, '')
    // Snapshot what we send: an edit committed WHILE this save is in flight (the
    // savingRows guard blocks a concurrent flush) lands in the live draft, so we
    // must not clear those newer edits on success — compare before dropping.
    const snapshot = { ...draft }
    const sentJson = JSON.stringify(snapshot)
    try {
      await config.saveRow(snapshot, row)
      // Refetch has the saved values now, so dropping the draft shows no flash —
      // but only when nothing changed since (else the newer edits would be lost).
      if (drafts[id] !== undefined && JSON.stringify({ ...drafts[id] }) === sentJson) {
        clearDraftState(id)
      }
      config.onSaved?.()
    } catch (caught) {
      // Keep the draft so edits aren't lost, and surface the failure. Auto-save
      // fires only once a row is complete (no invalid fields), so a rejection
      // here is a genuine server error (overlap, inactive project) with no field
      // hint to explain it — staying silent would hide a real failure.
      setRowErrors(id, config.saveErrorMessage?.(caught) ?? m.app_load_error())
    } finally {
      setSavingRows(id, false)
    }
    // Edits arrived during the save (draft kept + changed) → persist the newer
    // state (recomputes hints; auto-saves again only if still complete).
    if (drafts[id] !== undefined && JSON.stringify({ ...drafts[id] }) !== sentJson) {
      refreshHints(id)
    }
  }

  // Row-leave detection: when focus moves to a cell in a different row, save the
  // row we left (if dirty). focusin alone is robust — an editor closing and the
  // grid re-focusing the next cell both surface here.
  function rowIdOf(node: EventTarget | null): number | null {
    const cell = node instanceof HTMLElement ? node.closest<HTMLElement>('td[data-row-id]') : null

    return cell ? Number(cell.getAttribute('data-row-id')) : null
  }

  // A ChipSelect editor body-portals its listbox outside the table; focus inside
  // it must read as "still editing the row", not "left the table".
  const inChipSelectPopup = (node: EventTarget | null): boolean =>
    node instanceof HTMLElement && node.closest('[data-chipselect-popup]') !== null

  function onTableFocusIn(event: FocusEvent): void {
    if (inChipSelectPopup(event.target)) {
      return // focusing the portalled popup doesn't leave the current row
    }
    const id = rowIdOf(event.target)
    if (id === lastFocusedRowId) {
      return
    }
    if (lastFocusedRowId !== null) {
      void flushRow(lastFocusedRowId)
    }
    lastFocusedRowId = id
  }

  function flushIfFocusLeftTable(): void {
    const active = document.activeElement
    if (tableEl === undefined || (active !== null && (tableEl.contains(active) || inChipSelectPopup(active)))) {
      return
    }
    if (lastFocusedRowId !== null) {
      void flushRow(lastFocusedRowId)
    }
  }

  // Deferred so an editor unmounting + the grid re-focusing the next cell (all
  // synchronous) doesn't read as "left the table".
  function onTableFocusOut(): void {
    queueMicrotask(flushIfFocusLeftTable)
  }

  // Unmount / navigation away must not silently drop a pending edit.
  onCleanup(() => {
    for (const key of Object.keys(drafts)) {
      const id = Number(key)
      const row = rowById(id)
      if (row !== undefined && !savingRows[id] && (rowDirty(id) || isNewAndComplete(id, row))) {
        // Best-effort flush on unmount; the component is gone, so swallow a
        // failure (nowhere to surface it) rather than leak an unhandled rejection.
        void config.saveRow({ ...drafts[id]! }, row).catch(() => { /* discarded */ })
      }
    }
  })

  return {
    editCell,
    drafts,
    savingRows,
    rowErrors,
    fieldHints,
    /** True when the row has a missing/invalid draft value for this column. */
    fieldInvalid: (id: number, colKey: string): boolean => (fieldHints[id]?.includes(colKey) ?? false),
    seedChar: (): string | undefined => seedChar,
    overlayRow,
    isEditing,
    isDirty,
    draftValue,
    beginEdit,
    onActivate,
    commitCell,
    cancelCell,
    takeDraft,
    resetRow,
    flushRow,
    // Set a sibling field on an existing draft (e.g. after a ticket→project map).
    setDraftField: (id: number, colKey: string, value: FormValues[string]): void => {
      if (drafts[id] !== undefined) {
        setDrafts(id, colKey, value)
      }
    },
    setMoveHandle: (handle: GridMoveHandle | null): void => { moveHandle = handle },
    setTableEl: (el: HTMLElement | undefined): void => { tableEl = el },
    onTableFocusIn,
    onTableFocusOut,
  }
}
