import { useQueryClient, useQuery } from '@tanstack/solid-query'
import { createComputed, createMemo, createSignal, For, Match, onCleanup, Show, Switch } from 'solid-js'
import { createStore, reconcile } from 'solid-js/store'

import { apiErrorMessage, getJson, postJson } from '../api/client'
import { coerceActive, optionSourceKey } from '../api/queries'
import { chipValues, createInlineGridEdit, fieldSelectOptions, InlineEditor, INLINE_OVERLAY_TYPES, INLINE_TYPES, ReadonlyChips } from '../lib/inlineGridEdit'
import { ChipSelect } from '../lib/chipSelect'
import { gridNav } from '../lib/gridNavigation'
import { DiskIcon, DownloadIcon, EditIcon, TrashIcon } from '../lib/icons'
import { DateField } from './DateField'
import { PageDialog } from './PageDialog'
import { m } from '../paraglide/messages.js'
import type { ColumnDef, EntityDescriptor, FieldDef, FormValues, OptionLookup } from '../admin/types'

type Row = Record<string, unknown>


// Rows rendered per page. The list is fetched whole (a few hundred KB even for
// ~1k rows) but rendering every row at once is the cost — paging keeps the DOM,
// the gridNav ARIA sync and per-row work bounded.
const PAGE_SIZE = 50

// One CSV field: guard against formula injection (a leading =,+,-,@ is neutered
// with a leading apostrophe) and quote/escape per RFC 4180.
function csvCell(value: string): string {
  // Trim before the formula check: Excel strips leading whitespace and would
  // still run "   =cmd", so a non-trimmed test could be bypassed.
  const safe = /^[=+\-@]/.test(value.trim()) ? `'${value}` : value

  return /[",\r\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe
}


/** On/off indicator for a boolean column: a green dot for true, empty for false,
 *  with visually-hidden Yes/No so it isn't colour-only (WCAG 1.4.1). */
function BoolDot(props: Readonly<{ on: boolean }>) {
  return (
    <span class="bool-cell">
      <Show when={props.on}><span class="bool-dot" aria-hidden="true" /></Show>
      <span class="visually-hidden">{props.on ? m.app_yes() : m.app_no()}</span>
    </span>
  )
}

/**
 * Reusable admin CRUD surface: a list grid + add/edit modal form + delete,
 * driven entirely by an EntityDescriptor. The list responses are row-wrapped
 * ({user:{…}} etc.); save/delete go out as typed JSON (#[MapRequestPayload]).
 */
export function AdminCrudShell(props: {
  descriptor: EntityDescriptor
  options: OptionLookup
}) {
  const queryClient = useQueryClient()
  const listKey = () => ['admin-list', props.descriptor.key] as const

  const list = useQuery(() => ({
    queryKey: listKey(),
    queryFn: () => getJson<Row[]>(props.descriptor.listEndpoint),
  }))

  // A save/delete changes both this grid's rows and the shared option source
  // that other entities resolve relation labels from and that every edit-form
  // dropdown reads. The grid and the option source are separate caches of the
  // same endpoint (['admin-list', key] vs ['all-<key>']), so invalidate both —
  // otherwise cross-entity columns and dropdowns keep showing stale labels.
  async function refreshAfterMutation() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: listKey() }),
      queryClient.invalidateQueries({ queryKey: optionSourceKey(props.descriptor.key) }),
    ])
  }

  const [editing, setEditing] = createSignal<FormValues | null>(null)
  // A store (not a signal) so typing in one field updates only that field's
  // control instead of recreating the whole object and re-evaluating every
  // FieldControl on each keystroke.
  const [values, setValues] = createStore<FormValues>({})
  const [error, setError] = createSignal('')
  const [saving, setSaving] = createSignal(false)
  // Transient success confirmation (save/delete) shown in the toolbar.
  const [notice, setNotice] = createSignal('')
  let noticeTimer: ReturnType<typeof setTimeout> | undefined
  function flashNotice(message: string) {
    setNotice(message)
    clearTimeout(noticeTimer)
    noticeTimer = setTimeout(() => setNotice(''), 3000)
  }
  onCleanup(() => clearTimeout(noticeTimer))

  // List payloads are row-wrapped ({customer:{…}}, {user:{…}}, …). Unwrap by the
  // descriptor's rowKey and drop any row whose wrapper is missing or null —
  // handing the grid an undefined row would crash it (reading a column value
  // off undefined).
  const rows = createMemo<Row[]>(() =>
    (list.data ?? [])
      .map((row) => row?.[props.descriptor.rowKey])
      .filter((row): row is Row => row != null && typeof row === 'object'),
  )

  // Column sorting. cellText() yields exactly what the grid shows (id→label,
  // ✓/—, …) so the sort order matches the visible values. Clicking a header
  // cycles none → ascending → descending → none.
  const [sort, setSort] = createSignal<{ key: string; dir: 'asc' | 'desc' } | null>(null)
  // Free-text filter: matches against the visible text of every column.
  const [filter, setFilter] = createSignal('')
  // Entities with an `active` flag default to hiding inactive records.
  const hasActiveField = (): boolean => props.descriptor.fields.some((field) => field.name === 'active')
  const [hideInactive, setHideInactive] = createSignal(true)
  const [page, setPage] = createSignal(0)

  const cellText = (row: Row, col: ColumnDef): string =>
    col.render ? col.render(row, props.options) : String(row[col.key] ?? '')

  function toggleSort(key: string) {
    setSort((current) =>
      current?.key !== key ? { key, dir: 'asc' } : current.dir === 'asc' ? { key, dir: 'desc' } : null,
    )
  }

  const ariaSort = (key: string): 'ascending' | 'descending' | 'none' => {
    const current = sort()

    return current?.key === key ? (current.dir === 'asc' ? 'ascending' : 'descending') : 'none'
  }

  // Active column shows its direction; the rest show a dim neutral cue so
  // sortability is discoverable at rest (incl. on touch, where there's no hover).
  const sortGlyph = (key: string): string => {
    const current = sort()

    return current?.key === key ? (current.dir === 'asc' ? '▲' : '▼') : '⇅'
  }

  // Decorate each row with its full-text haystack ONCE per data/columns/options
  // change — not per keystroke. Filtering then only runs `.includes` on these
  // precomputed strings, so typing in the filter box stays cheap on big lists.
  const decorated = createMemo(() => {
    const columns = props.descriptor.columns

    return rows().map((row) => ({
      row,
      haystack: columns.map((col) => cellText(row, col)).join(' ').toLowerCase(),
    }))
  })

  const visibleRows = createMemo<Row[]>(() => {
    const current = sort()
    const query = filter().trim().toLowerCase()
    let matched = query === '' ? decorated() : decorated().filter((entry) => entry.haystack.includes(query))
    if (hasActiveField() && hideInactive()) {
      matched = matched.filter((entry) => Boolean(entry.row.active))
    }
    const sortCol = current && props.descriptor.columns.find((c) => c.key === current.key)
    if (!current || !sortCol) {
      return matched.map((entry) => entry.row)
    }
    const factor = current.dir === 'asc' ? 1 : -1

    return matched
      .map((entry) => ({ row: entry.row, key: cellText(entry.row, sortCol) }))
      .sort((a, b) => factor * a.key.localeCompare(b.key, undefined, { numeric: true }))
      .map((entry) => entry.row)
  })

  // Client-side paging over the filtered/sorted rows. Reset to the first page
  // whenever the result set changes (filter / sort / active toggle); the slice
  // start is clamped so a shrunk result set can't strand us past the last page.
  const pageCount = createMemo(() => Math.max(1, Math.ceil(visibleRows().length / PAGE_SIZE)))
  // Reset to the first page whenever the result set changes (visibleRows already
  // tracks filter/sort/active toggle + data refetches). createComputed runs in
  // the reactive phase before render, so there's no flash of the old page.
  createComputed(() => {
    visibleRows()
    setPage(0)
  })
  const pagedRows = createMemo<Row[]>(() => {
    const start = Math.min(page(), pageCount() - 1) * PAGE_SIZE

    return visibleRows().slice(start, start + PAGE_SIZE)
  })

  // Export the given rows as the column values shown (all-filtered, or selected).
  function exportCsv(rowsToExport: Row[]) {
    const columns = props.descriptor.columns
    const lines = [columns.map((col) => csvCell(col.label()))]
    for (const row of rowsToExport) {
      lines.push(columns.map((col) => csvCell(cellText(row, col))))
    }
    const csv = lines.map((cells) => cells.join(',')).join('\r\n')
    // Prepend a BOM (explicit escape, not a literal char) so Excel reads UTF-8.
    const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${props.descriptor.key}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  // ---- Row selection + bulk actions ---------------------------------------
  // Selection is keyed by stable row id and persists across pages/filtering.
  const [selected, setSelected] = createStore<Record<number, boolean>>({})
  const selectedRows = createMemo<Row[]>(() => visibleRows().filter((row) => selected[Number(row.id)]))
  const selectedCount = createMemo(() => selectedRows().length)
  // Header checkbox reflects/toggles the whole filtered set (across pages).
  const allSelected = createMemo(() => visibleRows().length > 0 && selectedRows().length === visibleRows().length)
  const [bulkBusy, setBulkBusy] = createSignal(false)
  // `indeterminate` is a DOM property (no HTML attribute), so drive it via a ref
  // + a reactive computed rather than JSX.
  let selectAllEl: HTMLInputElement | undefined
  const someSelected = (): boolean => selectedCount() > 0 && !allSelected()
  createComputed(() => {
    if (selectAllEl) {
      selectAllEl.indeterminate = someSelected()
    }
  })

  const toggleRow = (rowId: number, on: boolean) => setSelected(rowId, on)
  // Shallow-merge only the currently-visible rows so selections of rows hidden by
  // the filter/another page are preserved (selection persists across filtering).
  function toggleAll(on: boolean) {
    const updates: Record<number, boolean> = {}
    for (const row of visibleRows()) {
      updates[Number(row.id)] = on
    }
    setSelected(updates)
  }
  function clearSelection() {
    setSelected(reconcile({}))
  }

  // Run an async op over each selected row. Each row is deselected as it
  // succeeds, so on a mid-way failure the failed + unprocessed rows stay selected
  // (clear feedback); refresh always runs so the grid reflects partial success.
  async function runBulk(action: (row: Row) => Promise<void>, successMessage: () => string) {
    const targets = selectedRows()
    if (targets.length === 0 || bulkBusy()) {
      return
    }
    setBulkBusy(true)
    setError('')
    try {
      for (const row of targets) {
        await action(row)
        setSelected(Number(row.id), false)
      }
      flashNotice(successMessage())
    } catch (caught) {
      setError(apiErrorMessage(caught, m.app_load_error()))
    } finally {
      await refreshAfterMutation()
      setBulkBusy(false)
    }
  }

  function bulkSetActive(active: boolean) {
    const { saveEndpoint, toForm, toPayload } = props.descriptor
    void runBulk((row) => postJson(saveEndpoint, toPayload({ ...toForm(row), active })), () => m.admin_saved())
  }
  function bulkDelete() {
    if (!window.confirm(m.admin_bulk_delete_confirm({ count: String(selectedCount()) }))) {
      return
    }
    // Hoist the (reactive) descriptor reads out of the per-row closure; most
    // entities delete by numeric id, holidays and the like by deletePayload.
    const { deleteEndpoint, deletePayload } = props.descriptor
    void runBulk((row) => postJson(deleteEndpoint, deletePayload?.(row) ?? { id: row.id }), () => m.admin_deleted())
  }

  const fieldFor = (colKey: string): FieldDef | undefined => props.descriptor.fields.find((field) => field.name === colKey)

  function isColInlineEditable(colKey: string): boolean {
    if (props.descriptor.editable === false) {
      return false
    }
    const field = fieldFor(colKey)

    return field !== undefined && INLINE_TYPES.has(field.type) && field.lockedOnEdit !== true
  }

  const inlineEditable = (col: ColumnDef): boolean => isColInlineEditable(col.key)

  // The shared spreadsheet-edit controller (per-row drafts, save-on-row-leave);
  // gridNav stays the roving-tabindex owner. A row saves the whole entity.
  const editor = createInlineGridEdit({
    rows: visibleRows,
    fieldFor,
    isInlineEditable: isColInlineEditable,
    seedDraft: (row) => props.descriptor.toForm(row),
    saveRow: async (draft) => {
      await postJson(props.descriptor.saveEndpoint, props.descriptor.toPayload({ ...draft }))
      await refreshAfterMutation()
    },
    onModalActivate: (row) => { openForm(row); return true },
    onSaved: () => flashNotice(m.admin_saved()),
    saveErrorMessage: (caught) => apiErrorMessage(caught, m.app_load_error()),
    // Required fields must be filled for the row to auto-save; an empty one is
    // hinted (border) until then. A select/relation "none" is 0; for a numeric
    // field 0 is a real value, so it isn't treated as empty.
    invalidFields: (draft) => props.descriptor.fields
      .filter((field) => field.required === true)
      .filter((field) => {
        const value = draft[field.name]
        if (value === undefined || value === null || value === '') {
          return true
        }

        return field.type === 'select' && value === 0
      })
      .map((field) => field.name),
  })

  // A cell shows the committed draft value (through the column's own formatter)
  // while the row is dirty, else the persisted value.
  const displayText = (row: Row, col: ColumnDef): string => cellText(editor.overlayRow(row), col)

  function openForm(row: Row | null) {
    setError('')
    // If the row has a pending inline draft, the modal takes it over: seed from
    // the draft (not the now-stale list row) and drop the inline draft + open
    // editor so the inline and modal paths can't both save the same row.
    const rowId = row !== null ? Number(row.id) : 0
    const draft = rowId ? editor.takeDraft(rowId) : undefined
    const form = draft !== undefined ? draft : props.descriptor.toForm(row)
    // reconcile replaces every key (and drops stale ones) in one diffed update.
    setValues(reconcile(form))
    setEditing(form)
  }

  function setField(name: string, value: FormValues[string]) {
    setValues(name, value)
  }

  async function submit(event: SubmitEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await postJson(props.descriptor.saveEndpoint, props.descriptor.toPayload({ ...values }))
      await refreshAfterMutation()
      setEditing(null)
      flashNotice(m.admin_saved())
    } catch (caught) {
      setError(apiErrorMessage(caught, m.app_load_error()))
    } finally {
      setSaving(false)
    }
  }

  async function remove(row: Row) {
    if (!window.confirm(`${m.admin_delete_confirm()}\n${props.descriptor.rowLabel(row)}`)) {
      return
    }
    const rowId = Number(row.id)
    try {
      await postJson(props.descriptor.deleteEndpoint, props.descriptor.deletePayload?.(row) ?? { id: row.id })
      await refreshAfterMutation()
      // Drop any pending inline edit for the row that no longer exists.
      editor.takeDraft(rowId)
      flashNotice(m.admin_deleted())
    } catch (caught) {
      setError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  let searchEl: HTMLInputElement | undefined

  return (
    <div class="admin-crud">
      <div class="admin-crud-toolbar">
        <button type="button" class="primary-button" data-keyboard-add aria-keyshortcuts="Alt+A" onClick={() => openForm(null)}>
          {m.admin_add()}
        </button>
        <Show when={error()}>
          <span role="alert" class="form-status is-error">{error()}</span>
        </Show>
        <Show when={notice()}>
          <span role="status" class="form-status is-ok">{notice()}</span>
        </Show>
        <input
          ref={(el) => { searchEl = el }}
          type="search"
          class="admin-filter"
          placeholder={m.admin_filter()}
          aria-label={m.admin_filter()}
          aria-keyshortcuts="/"
          value={filter()}
          onInput={(event) => setFilter(event.currentTarget.value)}
          onKeyDown={(event) => {
            // The *active* entity, not merely the first (a grouped selector
            // would match the first DOM element of either kind).
            const subnav = () => document.querySelector<HTMLElement>('.admin-subnav-link[aria-current="page"]')
              ?? document.querySelector<HTMLElement>('.admin-subnav-link')
            if (event.key === 'ArrowUp') {
              // ArrowUp hands focus up to the entity sub-navigation (ArrowDown
              // back into the table is handled globally in header.ts).
              const el = subnav()
              if (el !== null) {
                event.preventDefault()
                el.focus()
              }
            } else if (event.key === 'Escape') {
              // Conventional Escape: clear the filter if it has text, else leave
              // the field back up to the sub-nav — never descend into the grid.
              event.preventDefault()
              if (filter() !== '') {
                setFilter('')
              } else {
                subnav()?.focus()
              }
            }
          }}
        />
        <Show when={hasActiveField()}>
          <label class="field-check admin-inactive-toggle">
            <input type="checkbox" checked={!hideInactive()} onInput={(event) => setHideInactive(!event.currentTarget.checked)} />
            <span>{m.admin_show_inactive()}</span>
          </label>
        </Show>
        <button type="button" class="action-button is-icon" aria-label={m.admin_export_csv()} title={m.admin_export_csv()} onClick={() => exportCsv(visibleRows())} disabled={visibleRows().length === 0}>
          <DownloadIcon />
        </button>
      </div>

      <Show when={selectedCount() > 0}>
        <div class="admin-bulk-bar" role="region" aria-label={m.admin_bulk_actions()} aria-busy={bulkBusy() ? 'true' : undefined}>
          <span class="admin-bulk-count" role="status" aria-live="polite">{m.admin_bulk_selected({ count: String(selectedCount()) })}</span>
          <Show when={hasActiveField()}>
            <button type="button" class="action-button" disabled={bulkBusy()} onClick={() => bulkSetActive(true)}>{m.admin_bulk_activate()}</button>
            <button type="button" class="action-button" disabled={bulkBusy()} onClick={() => bulkSetActive(false)}>{m.admin_bulk_deactivate()}</button>
          </Show>
          <button type="button" class="action-button is-icon" disabled={bulkBusy()} aria-label={m.admin_bulk_export()} title={m.admin_bulk_export()} onClick={() => exportCsv(selectedRows())}><DownloadIcon /></button>
          <button type="button" class="link-button is-icon is-danger" disabled={bulkBusy()} aria-label={m.admin_delete()} title={m.admin_delete()} onClick={() => bulkDelete()}><TrashIcon /></button>
          <button type="button" class="link-button" disabled={bulkBusy()} onClick={() => clearSelection()}>{m.admin_bulk_clear()}</button>
        </div>
      </Show>

      <Show when={props.descriptor.description !== undefined}>
        <p class="admin-intro">{props.descriptor.description?.()}</p>
      </Show>

      <Show when={!list.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table admin-table"
            ref={(el) => { editor.setTableEl(el) }}
            onFocusIn={editor.onTableFocusIn}
            onFocusOut={editor.onTableFocusOut}
            use:gridNav={{
              items: pagedRows,
              onExit: (dir) => { if (dir === 'up') searchEl?.focus() },
              onActivate: editor.onActivate,
              moveRef: (handle) => { editor.setMoveHandle(handle) },
              // Space ticks/unticks the cursor row (one keystroke, any cell).
              onRowSelectToggle: (cell) => {
                const id = Number(cell.getAttribute('data-row-id'))
                // A missing/non-numeric data-row-id yields NaN (NaN <= 0 is
                // false), so test for a positive integer to avoid a NaN key.
                if (!Number.isInteger(id) || id <= 0) {
                  return false
                }
                toggleRow(id, !selected[id])

                return true
              },
              // PageUp on the top row → previous page, PageDown on the bottom
              // row → next page (one keystroke, no scrolling to the pager).
              onPageEdge: (direction) => {
                const target = direction === 'prev' ? page() - 1 : page() + 1
                if (target < 0 || target >= pageCount()) {
                  return false
                }
                setPage(target)

                return true
              },
            }}
          >
            <thead>
              <tr>
                <th scope="col" class="boolean admin-select-col">
                  <input
                    ref={(el) => { selectAllEl = el; el.indeterminate = someSelected() }}
                    type="checkbox"
                    aria-label={m.admin_select_all()}
                    checked={allSelected()}
                    onInput={(event) => toggleAll(event.currentTarget.checked)}
                  />
                </th>
                <For each={props.descriptor.columns}>
                  {(col) => (
                    <th
                      scope="col"
                      classList={{ numeric: col.align === 'right', boolean: col.align === 'center' }}
                      aria-sort={ariaSort(col.key)}
                    >
                      <button type="button" class="th-sort" onClick={() => toggleSort(col.key)}>
                        <span>{col.label()}</span>
                        <span class="th-sort-glyph" aria-hidden="true">{sortGlyph(col.key)}</span>
                      </button>
                    </th>
                  )}
                </For>
                <th scope="col">{m.admin_actions()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={pagedRows()}>
                {(row) => (
                  <>
                  <tr aria-busy={editor.savingRows[Number(row.id)] ? 'true' : undefined} classList={{ 'is-selected': Boolean(selected[Number(row.id)]), 'is-dirty': editor.isDirty(Number(row.id)) }}>
                    <td class="boolean admin-select-col" data-row-id={String(Number(row.id))}>
                      <input
                        type="checkbox"
                        aria-label={m.admin_select_row({ label: props.descriptor.rowLabel(row) })}
                        checked={Boolean(selected[Number(row.id)])}
                        onInput={(event) => toggleRow(Number(row.id), event.currentTarget.checked)}
                      />
                    </td>
                    <For each={props.descriptor.columns}>
                      {(col) => {
                        const rowId = Number(row.id)
                        const editable = inlineEditable(col)
                        // A column whose field is modal-only (multiselect / locked
                        // relation) still opens the full form on double-click.
                        const modalOnly = !editable && fieldFor(col.key) !== undefined
                        const fieldType = fieldFor(col.key)?.type
                        // Single-line editors overlay a hidden ghost of the value (below)
                        // so opening one can't re-flow the auto-layout column.
                        const overlayEditor = fieldType !== undefined && INLINE_OVERLAY_TYPES.has(fieldType)

                        return (
                          <td
                            classList={{ numeric: col.align === 'right', boolean: col.align === 'center', 'is-editable': editable, 'is-invalid': editor.fieldInvalid(rowId, col.key) }}
                            data-row-id={String(rowId)}
                            data-col-key={col.key}
                            data-inline-editing={editor.isEditing(rowId, col.key) ? '' : undefined}
                            aria-label={editor.isEditing(rowId, col.key) ? col.label() : undefined}
                            onDblClick={() => {
                              if (editable) {
                                editor.beginEdit(rowId, col.key)
                              } else if (modalOnly) {
                                openForm(row)
                              }
                            }}
                          >
                            <Show
                              when={editor.isEditing(rowId, col.key)}
                              fallback={
                                col.boolean
                                  ? <BoolDot on={Boolean(editor.overlayRow(row)[col.key])} />
                                  : fieldType === 'select' || fieldType === 'multiselect'
                                    ? <ReadonlyChips values={chipValues(editor.overlayRow(row)[col.key])} options={fieldSelectOptions(fieldFor(col.key)!, props.options)} />
                                    : displayText(row, col)
                              }
                            >
                              <Show
                                when={fieldType === 'select' || fieldType === 'multiselect'}
                                fallback={
                                  <>
                                    {/* Hidden ghost holds the column width so the overlaying
                                        single-line editor can't make the table re-flow. */}
                                    <Show when={overlayEditor}>
                                      <span class="inline-ghost" aria-hidden="true">{displayText(row, col)}</span>
                                    </Show>
                                    <InlineEditor
                                      field={fieldFor(col.key)!}
                                      label={col.label()}
                                      initial={editor.draftValue(rowId, col.key) ?? ''}
                                      seed={editor.seedChar()}
                                      options={props.options}
                                      onCommit={editor.commitCell}
                                      onCancel={editor.cancelCell}
                                    />
                                  </>
                                }
                              >
                                {/* Ghost holds the column width so the overlaying single-select
                                    editor can't re-flow the table (multi-select wraps in flow). */}
                                <Show when={fieldType === 'select'}>
                                  <span class="inline-ghost" aria-hidden="true"><ReadonlyChips values={chipValues(editor.overlayRow(row)[col.key])} options={fieldSelectOptions(fieldFor(col.key)!, props.options)} /></span>
                                </Show>
                                <ChipSelect
                                  field={fieldFor(col.key)!}
                                  label={col.label()}
                                  initial={editor.draftValue(rowId, col.key) ?? (fieldType === 'multiselect' ? [] : '')}
                                  options={props.options}
                                  multiple={fieldType === 'multiselect'}
                                  onCommit={editor.commitCell}
                                  onCancel={editor.cancelCell}
                                />
                              </Show>
                            </Show>
                          </td>
                        )
                      }}
                    </For>
                    {/* data-row-id (no data-col-key → not inline-editable) keeps
                        focusing the row's action buttons "inside the row", so
                        clicking Edit doesn't read as a row-leave and flush. */}
                    <td class="admin-row-actions" data-row-id={String(Number(row.id))}>
                      <Show when={props.descriptor.editable !== false}>
                        <button type="button" class="link-button is-icon" aria-label={m.admin_edit()} title={m.admin_edit()} onClick={() => openForm(row)}>
                          <EditIcon />
                        </button>
                      </Show>
                      <button type="button" class="link-button is-icon is-danger" aria-label={m.admin_delete()} title={m.admin_delete()} onClick={() => void remove(row)}>
                        <TrashIcon />
                      </button>
                      {/* Force a full save (shows the full error if it fails). Always rendered as
                          the last action in a reserved slot — only its visibility toggles — so the
                          Edit/Delete icons never shift when a row becomes dirty. */}
                      <button type="button" class="link-button is-icon is-unsaved" classList={{ 'action-slot-hidden': !editor.isDirty(Number(row.id)) }} aria-label={m.app_save()} title={m.app_save()} onClick={() => void editor.flushRow(Number(row.id))}>
                        <DiskIcon />
                      </button>
                    </td>
                  </tr>
                  {/* Save error gets its own full-width row beneath the row, near
                      the data and not crammed into the icon-only actions cell. */}
                  <Show when={editor.rowErrors[Number(row.id)]}>
                    <tr class="row-error">
                      <td colspan={props.descriptor.columns.length + 2}>
                        <span role="alert" class="form-status is-error">{editor.rowErrors[Number(row.id)]}</span>
                      </td>
                    </tr>
                  </Show>
                  </>
                )}
              </For>
            </tbody>
          </table>
        </div>
        <Show when={pageCount() > 1}>
          <nav class="admin-pagination" aria-label={m.admin_pagination()}>
            <button type="button" class="action-button" disabled={page() <= 0} onClick={() => setPage((p) => Math.max(0, p - 1))}>
              {m.admin_page_prev()}
            </button>
            <span class="admin-page-status" role="status" aria-live="polite">
              {m.admin_page_status({ page: String(Math.min(page(), pageCount() - 1) + 1), pages: String(pageCount()), total: String(visibleRows().length) })}
            </span>
            <button type="button" class="action-button" disabled={page() >= pageCount() - 1} onClick={() => setPage((p) => Math.min(pageCount() - 1, p + 1))}>
              {m.admin_page_next()}
            </button>
          </nav>
        </Show>
        <Show when={filter().trim() !== '' && visibleRows().length === 0}>
          <p role="status" class="effort-empty admin-no-matches">{m.admin_no_matches()}</p>
        </Show>
        <Show when={filter().trim() === '' && !list.isPending && visibleRows().length === 0}>
          <p class="effort-empty admin-no-matches">{m.admin_empty()}</p>
        </Show>
      </Show>

      {/* The shared PageDialog (Ark UI) gives the edit form a real focus trap,
          focus-on-open, focus-return to the triggering button, scroll lock and
          Escape/outside dismissal. No header → chrome-less; aria-label names it. */}
      <PageDialog open={editing() !== null} onClose={() => setEditing(null)} ariaLabel={props.descriptor.title()}>
        <form class="stack-form" onSubmit={(event) => void submit(event)}>
          <For each={props.descriptor.fields}>
            {(field) => <FieldControl field={field} values={values} setField={setField} options={props.options} editing={editing() !== null && Number(values.id ?? 0) > 0} />}
          </For>
          <div class="form-actions">
            <button type="submit" class="primary-button" disabled={saving()}>
              {saving() ? m.app_saving() : m.app_save()}
            </button>
            <button type="button" class="action-button" onClick={() => setEditing(null)}>{m.admin_cancel()}</button>
            <Show when={error()}>
              <span role="alert" class="form-status is-error">{error()}</span>
            </Show>
          </div>
        </form>
      </PageDialog>
    </div>
  )
}

/** The optional ⓘ tooltip (hover title + AT aria-label). A real <button> so it's
 *  keyboard-focusable, and a click on it inside a <label> isn't forwarded to the
 *  field's own control (no toggle/focus). */
function FieldHelp(props: { field: FieldDef }) {
  return (
    <Show when={props.field.help !== undefined}>
      <button type="button" class="field-help" aria-label={props.field.help?.()} title={props.field.help?.()}>ⓘ</button>
    </Show>
  )
}

/** A field's label plus its optional ⓘ tooltip, for the non-checkbox label span. */
function FieldLabel(props: { field: FieldDef }) {
  return (
    <span class="field-label">
      {props.field.label()}
      <FieldHelp field={props.field} />
    </span>
  )
}

function FieldControl(props: {
  field: FieldDef
  values: FormValues
  setField: (name: string, value: FormValues[string]) => void
  options: OptionLookup
  editing: boolean
}) {
  const value = () => props.values[props.field.name]
  const disabled = () => props.editing && props.field.lockedOnEdit === true
  const text = () => String(value() ?? '')

  const selectOptions = createMemo(() => {
    if (props.field.activeOnly !== true || props.field.source === undefined) {
      return fieldSelectOptions(props.field, props.options)
    }
    // Hide deactivated users from assignment selects, but keep whatever is already
    // assigned (a now-inactive lead) so editing the record doesn't silently drop it.
    const current = value()

    return props
      .options(props.field.source)
      .filter((option) => coerceActive(option.active) || option.id === current)
      .map((option) => ({ value: option.id, label: option.label }))
  })

  const toggleMulti = (optionValue: number, checked: boolean) => {
    const current = new Set((props.values[props.field.name] as number[] | undefined) ?? [])
    if (checked) {
      current.add(optionValue)
    } else {
      current.delete(optionValue)
    }
    props.setField(props.field.name, [...current])
  }

  return (
    <Switch>
      <Match when={props.field.type === 'checkbox'}>
        <div class="field-check-row">
          <label class="field-check">
            <input type="checkbox" checked={Boolean(value())} onInput={(e) => props.setField(props.field.name, e.currentTarget.checked)} />
            <span>{props.field.label()}</span>
          </label>
          <FieldHelp field={props.field} />
        </div>
      </Match>
      <Match when={props.field.type === 'multiselect'}>
        <fieldset class="field multiselect">
          <legend><FieldLabel field={props.field} /></legend>
          <For each={selectOptions()}>
            {(option) => (
              <label class="field-check">
                <input
                  type="checkbox"
                  checked={((value() as number[] | undefined) ?? []).includes(Number(option.value))}
                  onInput={(e) => toggleMulti(Number(option.value), e.currentTarget.checked)}
                />
                <span>{option.label}</span>
              </label>
            )}
          </For>
        </fieldset>
      </Match>
      <Match when={props.field.type === 'select'}>
        <label class="field">
          <FieldLabel field={props.field} />
          <select
            required={props.field.required}
            disabled={disabled()}
            value={props.field.stringValue ? String(value() ?? '') : Number(value() ?? 0)}
            onInput={(e) => props.setField(props.field.name, props.field.stringValue ? e.currentTarget.value : Number(e.currentTarget.value))}
          >
            <option value={props.field.stringValue ? '' : 0}>—</option>
            <For each={selectOptions()}>{(option) => <option value={option.value}>{option.label}</option>}</For>
          </select>
        </label>
      </Match>
      <Match when={props.field.type === 'textarea'}>
        <label class="field">
          <FieldLabel field={props.field} />
          <textarea disabled={disabled()} value={text()} onInput={(e) => props.setField(props.field.name, e.currentTarget.value)} />
        </label>
      </Match>
      <Match when={props.field.type === 'date'}>
        <label class="field">
          <FieldLabel field={props.field} />
          <DateField
            value={text()}
            onChange={(iso) => props.setField(props.field.name, iso)}
            required={props.field.required}
            disabled={disabled()}
          />
        </label>
      </Match>
      <Match when={true}>
        <label class="field">
          <FieldLabel field={props.field} />
          <input
            type={props.field.type === 'number' ? 'number' : 'text'}
            required={props.field.required}
            disabled={disabled()}
            value={text()}
            onInput={(e) => props.setField(props.field.name, props.field.type === 'number' ? Number(e.currentTarget.value) : e.currentTarget.value)}
          />
        </label>
      </Match>
    </Switch>
  )
}

