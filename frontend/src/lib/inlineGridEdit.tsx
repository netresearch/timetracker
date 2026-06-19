import { createMemo, createSignal, createUniqueId, For, Match, onCleanup, onMount, Show, Switch, type Accessor } from 'solid-js'
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
export const INLINE_OVERLAY_TYPES = new Set<FieldDef['type']>(['text', 'number', 'date', 'select'])

/** Shared option resolution for both the modal FieldControl and the inline
 *  editor, so relation/static dropdowns stay identical in either surface. */
export function fieldSelectOptions(field: FieldDef, options: OptionLookup): { value: string | number; label: string }[] {
  if (field.staticOptions) {
    return field.staticOptions.map((option) => ({ value: option.value, label: option.label() }))
  }

  return field.source ? options(field.source).map((option) => ({ value: option.id, label: option.label })) : []
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
  onCommit: (value: FormValues[string], direction?: 'down' | 'left' | 'right' | 'stay') => void
  onCancel: () => void
}) {
  const isCheckbox = (): boolean => props.field.type === 'checkbox'
  const [value, setValue] = createSignal<string | boolean>(isCheckbox() ? Boolean(props.initial) : String(props.initial ?? ''))
  // Memoized so the <For> below sees a stable array (recreated only when the
  // option source changes), not a fresh one on every render.
  const selectOptions = createMemo(() => fieldSelectOptions(props.field, props.options))
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

  const finish = (direction?: 'down' | 'left' | 'right' | 'stay') => {
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
      // Enter's focus move is a user preference (default: stay in the cell);
      // Tab below always advances, so the user keeps an explicit move key.
      finish(getEnterBehavior())
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
    // A select editor auto-opens its option list, so Enter-to-edit visibly reveals
    // the dropdown rather than looking identical to read mode (the Enter keydown is
    // a transient user activation). Progressive — browsers without showPicker fall
    // back to the user pressing Space / Alt+Down.
    if (control instanceof HTMLSelectElement && typeof control.showPicker === 'function') {
      try {
        control.showPicker()
      } catch {
        // no transient user activation (or blocked) — ignore; Space/Alt+Down still works.
      }
    }
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
      <Match when={props.field.type === 'select'}>
        <select
          ref={(el) => { control = el }}
          class="inline-editor"
          aria-label={props.label}
          value={value() as string}
          onInput={(e) => setValue(e.currentTarget.value)}
          onKeyDown={onKeyDown}
          onBlur={() => finish()}
        >
          <option value="">—</option>
          <For each={selectOptions()}>
            {(option) => <option value={String(option.value)}>{option.label}</option>}
          </For>
        </select>
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

/**
 * In-cell multiselect editor: the selected options render as removable tag chips
 * with an "add" dropdown of the remaining options (Teams: [DEV ×] [PL ×] +). The
 * value is the selected ids (number[]). Commit moves through the same onCommit
 * as the single-cell editor: Enter commits in place, Escape cancels, and tabbing
 * or clicking out of the whole widget commits (checked after the focus settles,
 * so moving between chips/the dropdown — incl. removing one — doesn't commit).
 */
export function InlineMultiSelect(props: {
  field: FieldDef
  label: string
  initial: FormValues[string]
  options: OptionLookup
  onCommit: (value: FormValues[string], direction?: 'down' | 'left' | 'right' | 'stay') => void
  onCancel: () => void
}) {
  const allOptions = createMemo(() => fieldSelectOptions(props.field, props.options))
  const [selected, setSelected] = createSignal<number[]>(Array.isArray(props.initial) ? [...(props.initial as number[])] : [])
  const unselected = createMemo(() => allOptions().filter((option) => !selected().includes(Number(option.value))))
  // id→label map (rebuilt only when the options change) so resolving a chip's
  // name is O(1) rather than an O(n) .find on each of its (re)renders.
  const optionLabels = createMemo(() => new Map(allOptions().map((option) => [Number(option.value), option.label])))
  const labelOf = (id: number): string => optionLabels().get(id) ?? String(id)
  // The "add" control is a popup MENU OF BUTTONS, not a native <select> (which
  // fired change on every arrow keypress, adding a chip per keystroke) and not a
  // role=listbox + aria-activedescendant (invalid ARIA on a <button>). Real,
  // focusable <button> options are keyboard-native and accessible.
  const [menuOpen, setMenuOpen] = createSignal(false)
  let container: HTMLDivElement | undefined
  let addBtn: HTMLButtonElement | undefined
  let menuEl: HTMLDivElement | undefined
  let done = false

  function add(id: number): void {
    if (id > 0 && !selected().includes(id)) {
      setSelected([...selected(), id])
    }
  }
  function remove(id: number): void {
    setSelected(selected().filter((value) => value !== id))
  }
  // Focusable items, in DOM order: each chip, then the add button. Chips carry a
  // roving tabindex so Left/Right can move between them (and the add button).
  const navItems = (): HTMLElement[] => Array.from(container?.querySelectorAll<HTMLElement>('.tag, .tag-add') ?? [])
  function moveFocus(delta: number): void {
    const items = navItems()
    const current = items.indexOf(document.activeElement as HTMLElement)
    const from = current === -1 ? items.length - 1 : current
    items[Math.max(0, Math.min(items.length - 1, from + delta))]?.focus()
  }
  // Remove the focused chip, then keep focus inside the widget (the chip now in
  // its place, or the add button) so the removal doesn't read as a commit.
  function removeFocusedChip(): void {
    const active = document.activeElement
    if (!(active instanceof HTMLElement) || !active.classList.contains('tag')) {
      return
    }
    const index = navItems().indexOf(active)
    const id = Number(active.dataset.tagId)
    if (!Number.isInteger(id) || id <= 0) {
      return
    }
    remove(id)
    // Re-focus the next item SYNCHRONOUSLY (Solid has already re-rendered the
    // removed chip away): the focus-out check is deferred to a microtask, so
    // focus must be back inside the widget before it runs, or the removal would
    // read as "focus left" and commit/close the editor.
    const after = navItems()
    ;(after[Math.min(index, after.length - 1)] ?? addBtn)?.focus()
  }
  const focusFirstItem = (): void => {
    queueMicrotask(() => menuEl?.querySelector<HTMLButtonElement>('.tag-menu-item')?.focus())
  }
  function openMenu(): void {
    if (unselected().length > 0) {
      setMenuOpen(true)
      focusFirstItem()
    }
  }
  function closeMenu(refocusAddBtn = true): void {
    setMenuOpen(false)
    if (refocusAddBtn) {
      addBtn?.focus()
    }
  }
  // Add an option, keeping the menu usable for adding several in a row (it closes
  // once nothing is left to add).
  function addOption(id: number): void {
    add(id)
    if (unselected().length === 0) {
      closeMenu()
    } else {
      focusFirstItem()
    }
  }
  // Takes the value so the signal is read in the (tracked) event handler, not in
  // the deferred focusout microtask.
  const finish = (value: number[], direction?: 'down' | 'left' | 'right' | 'stay') => {
    if (done) {
      return
    }
    done = true
    props.onCommit([...value], direction)
  }
  const cancel = () => {
    if (!done) {
      done = true
      props.onCancel()
    }
  }

  onMount(() => addBtn?.focus())

  return (
    <div
      ref={(el) => { container = el }}
      class="inline-editor inline-tags"
      role="group"
      aria-label={props.label}
      onKeyDown={(event) => {
        // While the add-menu is open it owns the arrow/enter/escape keys (handled
        // on the button below); don't let them commit/cancel the whole edit.
        if (menuOpen()) {
          return
        }
        const onChip = document.activeElement instanceof HTMLElement && document.activeElement.classList.contains('tag')
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          // Move between chips and the add button.
          event.preventDefault()
          event.stopPropagation()
          moveFocus(event.key === 'ArrowLeft' ? -1 : 1)
        } else if (event.key === 'Enter') {
          event.preventDefault()
          event.stopPropagation()
          finish(selected(), 'stay')
        } else if (event.key === 'Tab') {
          // Keep spreadsheet cell-to-cell nav: commit and move to the adjacent
          // cell rather than tabbing focus out of the grid entirely.
          event.preventDefault()
          event.stopPropagation()
          finish(selected(), event.shiftKey ? 'left' : 'right')
        } else if (event.key === 'Escape') {
          event.preventDefault()
          event.stopPropagation()
          cancel()
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
          event.preventDefault()
          if (onChip) {
            removeFocusedChip() // remove the chip the user navigated to
          } else if (event.key === 'Backspace' && selected().length > 0) {
            remove(selected()[selected().length - 1]!) // tag-input convention: Backspace removes the last
            addBtn?.focus()
          }
        }
      }}
      onFocusOut={() => {
        // Capture the value now (tracked handler), then commit only when focus
        // has actually left the whole widget — checked after it settles, so
        // moving between chips/the menu (incl. removing one) doesn't commit.
        const value = selected()
        // eslint-disable-next-line solid/reactivity -- intentional deferred commit of the captured value after focus settles
        queueMicrotask(() => {
          if (!done && container !== undefined && !container.contains(document.activeElement)) {
            finish(value)
          }
        })
      }}
    >
      {/* role=list over the selected tags gives each chip a name+role+position for
          AT (display:contents keeps the chips in the parent's flex flow). */}
      <span class="tag-list" role="list">
        <For each={selected()}>
          {(id) => (
            // Focusable (roving tabindex) so Left/Right reach it and Delete/Backspace
            // removes it; aria-label names the tag for screen readers.
            <span class="tag" role="listitem" tabindex="-1" data-tag-id={id} aria-label={labelOf(id)}>
              <span class="tag-label">{labelOf(id)}</span>
              {/* preventDefault on mousedown so clicking × doesn't blur the widget
                  (which would commit + unmount before the click removes the chip). */}
              <button type="button" class="tag-remove" tabindex="-1" aria-label={`${m.admin_delete()}: ${labelOf(id)}`} onMouseDown={(event) => event.preventDefault()} onClick={() => { remove(id); addBtn?.focus() }}>×</button>
            </span>
          )}
        </For>
      </span>
      <span class="tag-add-wrap">
        <button
          ref={(el) => { addBtn = el }}
          type="button"
          class="tag-add"
          aria-haspopup="true"
          aria-expanded={menuOpen()}
          aria-label={`${m.admin_add()} — ${props.label}`}
          onClick={() => (menuOpen() ? closeMenu() : openMenu())}
          onKeyDown={(event) => {
            // Closed: Down/Space open the menu; Enter/Tab/Escape/Backspace bubble
            // to the container (commit / move / cancel / remove last).
            if (!menuOpen() && (event.key === 'ArrowDown' || event.key === ' ')) {
              event.preventDefault()
              event.stopPropagation()
              openMenu()
            }
          }}
        >+</button>
        <Show when={menuOpen()}>
          <div
            ref={(el) => { menuEl = el }}
            class="tag-menu"
            role="menu"
            aria-label={props.label}
            onKeyDown={(event) => {
              const items = Array.from(menuEl?.querySelectorAll<HTMLButtonElement>('.tag-menu-item') ?? [])
              const i = items.indexOf(document.activeElement as HTMLButtonElement)
              if (event.key === 'ArrowDown') {
                event.preventDefault()
                event.stopPropagation()
                items[Math.min(items.length - 1, i + 1)]?.focus()
              } else if (event.key === 'ArrowUp') {
                event.preventDefault()
                event.stopPropagation()
                items[Math.max(0, i - 1)]?.focus()
              } else if (event.key === 'Escape') {
                event.preventDefault()
                event.stopPropagation()
                closeMenu()
              } else if (event.key === 'Tab') {
                closeMenu(false) // close, then let the container's Tab move to the next cell
              }
            }}
          >
            <For each={unselected()}>
              {(option) => (
                <button type="button" role="menuitem" class="tag-menu-item" onClick={() => addOption(Number(option.value))}>{option.label}</button>
              )}
            </For>
          </div>
        </Show>
      </span>
    </div>
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

  // Drop a draft that ended up identical to its original row (opened a cell and
  // left without changing it, or edited a value back) so the row shows no dirty
  // state and never triggers a no-op save.
  function discardIfClean(id: number): void {
    if (drafts[id] !== undefined && !rowDirty(id)) {
      setDrafts(produce((store) => { delete store[id] }))
      setFieldHints(produce((store) => { delete store[id] }))
      originalRows.delete(id)
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

  function commitCell(value: FormValues[string], direction?: 'down' | 'left' | 'right' | 'stay'): void {
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
    // then sees no draft and skips the (pointless) auto-save.
    discardIfClean(cell.rowId)
    refreshHints(cell.rowId)
    // All focus moves go through the grid's setActive (the single roving-tabindex
    // writer); landing on a different row triggers that row's save via focusin.
    if (direction === 'stay') {
      moveHandle?.focusActive()
    } else if (direction) {
      moveHandle?.move(direction)
    }
  }

  // After a commit: recompute the row's invalid-field hints and, when the row is
  // complete (no invalid fields), auto-save it quietly. Saving no longer depends
  // on leaving the row. A no-op when the host provides no invalidFields.
  function refreshHints(id: number): void {
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
    if (invalid.length === 0) {
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
    setDrafts(produce((store) => { delete store[id] }))
    setFieldHints(produce((store) => { delete store[id] }))
    originalRows.delete(id)

    return { ...draft }
  }

  async function flushRow(id: number): Promise<void> {
    const draft = drafts[id]
    const row = rowById(id)
    if (draft === undefined || row === undefined || savingRows[id]) {
      return
    }
    // Nothing actually changed (e.g. the row was only navigated through) — drop
    // the no-op draft instead of POSTing an identical row.
    if (!rowDirty(id)) {
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
        setDrafts(produce((store) => { delete store[id] }))
        setFieldHints(produce((store) => { delete store[id] }))
        originalRows.delete(id)
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

  function onTableFocusIn(event: FocusEvent): void {
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
    if (tableEl === undefined || (document.activeElement !== null && tableEl.contains(document.activeElement))) {
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
      if (rowDirty(id) && row !== undefined && !savingRows[id]) {
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
