import { createMemo, createSignal, For, Match, onCleanup, onMount, Switch, type Accessor } from 'solid-js'
import { createStore, produce } from 'solid-js/store'

import type { FieldDef, FormValues, OptionLookup } from '../admin/types'
import { getEnterBehavior } from './gridEditPref'
import type { ActivateKey, GridMoveHandle } from './gridNavigation'
import { m } from '../paraglide/messages.js'

export type Row = Record<string, unknown>

// Field types that get a compact in-cell editor; everything else (multiselect,
// locked relation columns) stays modal-only.
export const INLINE_TYPES = new Set<FieldDef['type']>(['text', 'number', 'date', 'checkbox', 'select'])

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
          type={props.field.type === 'number' ? 'number' : props.field.type === 'date' ? 'date' : 'text'}
          class="inline-editor"
          aria-label={props.label}
          value={value() as string}
          onInput={(e) => setValue(e.currentTarget.value)}
          onKeyDown={onKeyDown}
          onBlur={() => finish()}
        />
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
  const isDirty = (id: number): boolean => drafts[id] !== undefined

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
    // All focus moves go through the grid's setActive (the single roving-tabindex
    // writer); landing on a different row triggers that row's save via focusin.
    if (direction === 'stay') {
      moveHandle?.focusActive()
    } else if (direction) {
      moveHandle?.move(direction)
    }
  }

  function cancelCell(): void {
    seedChar = undefined
    setEditCell(null)
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
    originalRows.delete(id)

    return { ...draft }
  }

  async function flushRow(id: number): Promise<void> {
    const draft = drafts[id]
    const row = rowById(id)
    if (draft === undefined || row === undefined || savingRows[id]) {
      return
    }
    setSavingRows(id, true)
    setRowErrors(id, '')
    try {
      await config.saveRow({ ...draft }, row)
      // Refetch has the saved values now, so dropping the draft shows no flash.
      setDrafts(produce((store) => { delete store[id] }))
      originalRows.delete(id)
      config.onSaved?.()
    } catch (caught) {
      // Keep the draft so edits aren't lost; surface a per-row error.
      setRowErrors(id, config.saveErrorMessage?.(caught) ?? m.app_load_error())
    } finally {
      setSavingRows(id, false)
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
      if (drafts[id] !== undefined && row !== undefined && !savingRows[id]) {
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
