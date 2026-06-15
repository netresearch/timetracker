import { Dialog } from '@ark-ui/solid/dialog'
import { useQueryClient, useQuery } from '@tanstack/solid-query'
import { createComputed, createMemo, createSignal, For, Match, onCleanup, onMount, Show, Switch } from 'solid-js'
import { createStore, produce, reconcile } from 'solid-js/store'
import { Portal } from 'solid-js/web'

import { apiErrorMessage, getJson, postJson } from '../api/client'
import { optionSourceKey } from '../api/queries'
import { getEnterBehavior } from '../lib/gridEditPref'
import { gridNav, type ActivateKey, type GridMoveHandle } from '../lib/gridNavigation'
import { m } from '../paraglide/messages.js'
import type { ColumnDef, EntityDescriptor, FieldDef, FormValues, OptionLookup } from '../admin/types'

type Row = Record<string, unknown>

// Field types that get a compact in-cell editor; everything else (multiselect,
// the locked relation columns) stays modal-only.
const INLINE_TYPES = new Set<FieldDef['type']>(['text', 'number', 'date', 'checkbox', 'select'])

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

/** Shared option resolution for both the modal FieldControl and the inline
 *  editor, so relation/static dropdowns stay identical in either surface. */
function fieldSelectOptions(field: FieldDef, options: OptionLookup): { value: string | number; label: string }[] {
  if (field.staticOptions) {
    return field.staticOptions.map((option) => ({ value: option.value, label: option.label() }))
  }

  return field.source ? options(field.source).map((option) => ({ value: option.id, label: option.label })) : []
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

  // Export the filtered/sorted rows (all pages) as the column values shown.
  function exportCsv() {
    const columns = props.descriptor.columns
    const lines = [columns.map((col) => csvCell(col.label()))]
    for (const row of visibleRows()) {
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

  function openForm(row: Row | null) {
    setError('')
    // If the row has a pending inline draft, the modal takes it over: seed from
    // the draft (not the now-stale list row) and drop the inline draft + open
    // editor so the inline and modal paths can't both save the same row.
    const rowId = row !== null ? Number(row.id) : 0
    const draft = rowId ? drafts[rowId] : undefined
    const form = draft !== undefined ? { ...draft } : props.descriptor.toForm(row)
    if (draft !== undefined) {
      if (editCell()?.rowId === rowId) {
        setEditCell(null)
      }
      setDrafts(produce((store) => { delete store[rowId] }))
    }
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
      await postJson(props.descriptor.deleteEndpoint, { id: row.id })
      await refreshAfterMutation()
      // Drop any pending inline edit for the row that no longer exists.
      setDrafts(produce((store) => { delete store[rowId] }))
      flashNotice(m.admin_deleted())
    } catch (caught) {
      setError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  // ---- Inline cell editing -------------------------------------------------
  // The grid stays a pure navigation/ARIA controller (use:gridNav); all edit
  // state lives here, keyed by stable row id so a sort/filter/refetch can't
  // desync it. A cell commit accumulates onto its row's draft and the whole
  // entity is saved once, when focus leaves the row (see onTableFocusIn).
  const [editCell, setEditCell] = createSignal<{ rowId: number; colKey: string } | null>(null)
  const [drafts, setDrafts] = createStore<Record<number, FormValues>>({})
  const [savingRows, setSavingRows] = createStore<Record<number, boolean>>({})
  const [rowErrors, setRowErrors] = createStore<Record<number, string>>({})
  let seedChar: string | undefined
  let moveHandle: GridMoveHandle | null = null

  const fieldFor = (colKey: string): FieldDef | undefined => props.descriptor.fields.find((f) => f.name === colKey)

  function inlineEditable(col: ColumnDef): boolean {
    const field = fieldFor(col.key)

    return field !== undefined && INLINE_TYPES.has(field.type) && field.lockedOnEdit !== true
  }

  const isEditing = (rowId: number, colKey: string): boolean => {
    const cell = editCell()

    return cell !== null && cell.rowId === rowId && cell.colKey === colKey
  }

  // What a cell shows: the committed draft value (rendered through the column's
  // own formatter) while the row is dirty, else the persisted value. This keeps
  // edited-but-unsaved cells visibly current without touching the query cache.
  function displayText(row: Row, col: ColumnDef): string {
    const draft = drafts[Number(row.id)]
    if (draft !== undefined && col.key in draft) {
      return cellText({ ...row, [col.key]: draft[col.key] }, col)
    }

    return cellText(row, col)
  }

  function beginEdit(rowId: number, colKey: string, char?: string): boolean {
    const col = props.descriptor.columns.find((c) => c.key === colKey)
    if (!col || !inlineEditable(col)) {
      return false
    }
    const row = visibleRows().find((r) => Number(r.id) === rowId)
    if (!row) {
      return false
    }
    if (drafts[rowId] === undefined) {
      setDrafts(rowId, props.descriptor.toForm(row))
    }
    const field = fieldFor(colKey)
    // Only text-like fields are seeded with the keystroke that opened them.
    seedChar = char !== undefined && (field?.type === 'text' || field?.type === 'number' || field?.type === 'date') ? char : undefined
    setRowErrors(rowId, '')
    setEditCell({ rowId, colKey })

    return true
  }

  // gridNav offers the focused cell on Enter/F2/printable; we open an editor and
  // return true so the grid suppresses its default "focus first control".
  const onActivate = (cell: HTMLTableCellElement, key: ActivateKey, initial?: string): boolean => {
    const rowId = Number(cell.getAttribute('data-row-id'))
    const colKey = cell.getAttribute('data-col-key')
    if (!rowId || colKey === null) {
      return false
    }
    const col = props.descriptor.columns.find((c) => c.key === colKey)
    if (col && inlineEditable(col)) {
      return beginEdit(rowId, colKey, key === 'type' ? initial : undefined)
    }
    // A column backed by a modal-only field (multiselect / locked relation, e.g.
    // Customers → Teams) can't edit in place — Enter/F2 open the full edit modal
    // so the field stays reachable from the keyboard instead of doing nothing.
    if (col && fieldFor(colKey) !== undefined && key !== 'type') {
      const row = visibleRows().find((r) => Number(r.id) === rowId)
      if (row) {
        openForm(row)

        return true
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
    seedChar = undefined
    setEditCell(null)
    // All focus moves go through the grid's own setActive (the single
    // roving-tabindex writer); landing on a different row triggers that row's
    // save via onTableFocusIn. 'stay' re-focuses the just-edited cell; an
    // undefined direction (commit-on-blur) leaves focus wherever it went.
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

  async function flushRow(rowId: number): Promise<void> {
    const draft = drafts[rowId]
    if (draft === undefined || savingRows[rowId]) {
      return
    }
    setSavingRows(rowId, true)
    setRowErrors(rowId, '')
    try {
      await postJson(props.descriptor.saveEndpoint, props.descriptor.toPayload({ ...draft }))
      await refreshAfterMutation()
      // Refetch has the saved values now, so dropping the draft shows no flash.
      setDrafts(produce((store) => { delete store[rowId] }))
      flashNotice(m.admin_saved())
    } catch (caught) {
      // Keep the draft so the user's edits aren't lost; surface a per-row error.
      setRowErrors(rowId, apiErrorMessage(caught, m.app_load_error()))
    } finally {
      setSavingRows(rowId, false)
    }
  }

  // Row-leave detection: when focus moves to a cell in a different row, save the
  // row we left (if dirty). Tracking focusin alone is robust — an editor closing
  // and the grid re-focusing the next cell both surface here.
  let tableEl: HTMLTableElement | undefined
  let lastFocusedRowId: number | null = null

  function rowIdOf(node: EventTarget | null): number | null {
    const cell = node instanceof HTMLElement ? node.closest<HTMLElement>('td[data-row-id]') : null

    return cell ? Number(cell.getAttribute('data-row-id')) : null
  }

  function onTableFocusIn(event: FocusEvent): void {
    const rowId = rowIdOf(event.target)
    if (rowId === lastFocusedRowId) {
      return
    }
    if (lastFocusedRowId !== null) {
      void flushRow(lastFocusedRowId) // a no-op when that row has no pending draft
    }
    lastFocusedRowId = rowId
  }

  function flushIfFocusLeftTable(): void {
    if (tableEl === undefined || (document.activeElement !== null && tableEl.contains(document.activeElement))) {
      return
    }
    if (lastFocusedRowId !== null) {
      void flushRow(lastFocusedRowId) // a no-op when that row has no pending draft
    }
  }

  function onTableFocusOut(): void {
    // Deferred so an editor unmounting + the grid re-focusing the next cell (all
    // synchronous) doesn't read as "left the table"; by the microtask the dust
    // has settled and document.activeElement is authoritative.
    queueMicrotask(flushIfFocusLeftTable)
  }

  // Switching entity / unmounting must not silently drop a pending edit.
  onCleanup(() => {
    for (const key of Object.keys(drafts)) {
      const rowId = Number(key)
      if (drafts[rowId] !== undefined && !savingRows[rowId]) {
        void postJson(props.descriptor.saveEndpoint, props.descriptor.toPayload({ ...drafts[rowId]! }))
      }
    }
  })

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
        <button type="button" class="action-button" onClick={() => exportCsv()} disabled={visibleRows().length === 0}>
          {m.admin_export_csv()}
        </button>
      </div>

      <Show when={!list.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table admin-table"
            ref={(el) => { tableEl = el }}
            onFocusIn={onTableFocusIn}
            onFocusOut={onTableFocusOut}
            use:gridNav={{
              items: pagedRows,
              onExit: (dir) => { if (dir === 'up') searchEl?.focus() },
              onActivate,
              moveRef: (handle) => { moveHandle = handle },
            }}
          >
            <thead>
              <tr>
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
                  <tr aria-busy={savingRows[Number(row.id)] ? 'true' : undefined}>
                    <For each={props.descriptor.columns}>
                      {(col) => {
                        const rowId = Number(row.id)
                        const editable = inlineEditable(col)
                        // A column whose field is modal-only (multiselect / locked
                        // relation) still opens the full form on double-click.
                        const modalOnly = !editable && fieldFor(col.key) !== undefined

                        return (
                          <td
                            classList={{ numeric: col.align === 'right', boolean: col.align === 'center', 'is-editable': editable }}
                            data-row-id={String(rowId)}
                            data-col-key={col.key}
                            data-inline-editing={isEditing(rowId, col.key) ? '' : undefined}
                            aria-label={isEditing(rowId, col.key) ? col.label() : undefined}
                            onDblClick={() => {
                              if (editable) {
                                beginEdit(rowId, col.key)
                              } else if (modalOnly) {
                                openForm(row)
                              }
                            }}
                          >
                            <Show when={isEditing(rowId, col.key)} fallback={displayText(row, col)}>
                              <InlineEditor
                                field={fieldFor(col.key)!}
                                label={col.label()}
                                initial={drafts[rowId]?.[col.key] ?? ''}
                                seed={seedChar}
                                options={props.options}
                                onCommit={commitCell}
                                onCancel={cancelCell}
                              />
                            </Show>
                          </td>
                        )
                      }}
                    </For>
                    {/* data-row-id (no data-col-key → not inline-editable) keeps
                        focusing the row's action buttons "inside the row", so
                        clicking Edit doesn't read as a row-leave and flush. */}
                    <td class="admin-row-actions" data-row-id={String(Number(row.id))}>
                      <button type="button" class="link-button" onClick={() => openForm(row)}>
                        <svg class="action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        {m.admin_edit()}
                      </button>
                      <button type="button" class="link-button is-danger" onClick={() => void remove(row)}>
                        <svg class="action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                        {m.admin_delete()}
                      </button>
                      <Show when={rowErrors[Number(row.id)]}>
                        <span role="alert" class="form-status is-error">{rowErrors[Number(row.id)]}</span>
                      </Show>
                    </td>
                  </tr>
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

      {/* Ark UI Dialog gives the edit form a real focus trap, focus-on-open,
          focus-return to the triggering button, scroll lock and Escape/outside
          dismissal — handled by the library rather than the previous hand-rolled
          backdrop. */}
      <Dialog.Root
        open={editing() !== null}
        onOpenChange={(details) => { if (!details.open) setEditing(null) }}
        lazyMount
        unmountOnExit
      >
        <Portal>
          <Dialog.Backdrop class="modal-backdrop" />
          <Dialog.Positioner class="modal-positioner">
            <Dialog.Content class="modal" aria-label={props.descriptor.title()}>
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
            </Dialog.Content>
          </Dialog.Positioner>
        </Portal>
      </Dialog.Root>
    </div>
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

  const selectOptions = createMemo(() => fieldSelectOptions(props.field, props.options))

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
        <label class="field-check">
          <input type="checkbox" checked={Boolean(value())} onInput={(e) => props.setField(props.field.name, e.currentTarget.checked)} />
          <span>{props.field.label()}</span>
        </label>
      </Match>
      <Match when={props.field.type === 'multiselect'}>
        <fieldset class="field multiselect">
          <legend>{props.field.label()}</legend>
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
          <span>{props.field.label()}</span>
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
          <span>{props.field.label()}</span>
          <textarea disabled={disabled()} value={text()} onInput={(e) => props.setField(props.field.name, e.currentTarget.value)} />
        </label>
      </Match>
      <Match when={true}>
        <label class="field">
          <span>{props.field.label()}</span>
          <input
            type={props.field.type === 'number' ? 'number' : props.field.type === 'date' ? 'date' : 'text'}
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

/**
 * Compact in-cell editor for inline (spreadsheet-style) editing. It owns its
 * keys: Enter commits and moves down, Tab/Shift+Tab commit and move right/left,
 * Escape cancels; all four stopPropagation so neither the grid nor the global
 * shortcut handler sees them. Blur commits in place (click-away). The value is
 * coerced back to the field's payload type on commit, matching FieldControl.
 */
function InlineEditor(props: {
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
