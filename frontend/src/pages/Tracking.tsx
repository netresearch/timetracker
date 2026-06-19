import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createMemo, createSignal, For, onCleanup, onMount, Show, type JSX } from 'solid-js'

import { apiErrorMessage, postForm, postJson } from '../api/client'
import { activitiesQuery, trackingCustomersQuery, trackingEntriesQuery, trackingProjectsQuery, trackingTicketSystemsQuery, type NamedOption, type SummaryScope, type TrackingEntry } from '../api/queries'
import { appConfig, canBulkEnter } from '../config'
import type { FieldDef, OptionLookup, OptionSource } from '../admin/types'
import { num, str } from '../lib/coerce'
import { formatMinutes } from '../lib/format'
import { gridNav, type GridMoveHandle } from '../lib/gridNavigation'
import { createInlineGridEdit, InlineEditor, INLINE_OVERLAY_TYPES, INLINE_TYPES } from '../lib/inlineGridEdit'
import { ContinueIcon, DiskIcon, DownloadIcon, InfoIcon, PlusIcon, ProlongIcon, TrashIcon } from '../lib/icons'
import { BulkEntryForm } from '../components/BulkEntryForm'
import { PageDialog } from '../components/PageDialog'
import { dmyToIso, parseTime, toIsoDate } from '../lib/timeParse'
import { m } from '../paraglide/messages.js'

// Register the directive with the JSX namespace (Solid tree-shakes unused imports).
void gridNav

const DAYS_OPTIONS = [1, 3, 7, 35] as const
const DEFAULT_DAYS = 3
const ENTRIES_KEY = 'tracking-entries'

// Server-computed EntryClass → row modifier, mirroring the ExtJS row borders.
const CLASS_ROW: Record<number, string> = { 2: 'is-daybreak', 4: 'is-pause', 8: 'is-overlap' }

// Build the /tracking/save body shared by inline-save and Prolong. A non-positive
// id is omitted so the server creates a new entry.
function savePayload(fields: {
  id: number
  date: string
  start: string
  end: string
  ticket: unknown
  description: unknown
  customer: unknown
  project: unknown
  activity: unknown
  extTicket: unknown
}): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    date: fields.date,
    start: fields.start,
    end: fields.end,
    ticket: str(fields.ticket).toUpperCase().trim(),
    description: str(fields.description),
    customer: num(fields.customer),
    project: num(fields.project),
    activity: num(fields.activity),
    // Round-trip the mirrored-entry's original Jira key — the backend resets it
    // to null when this is absent, which silently breaks worklog remapping.
    extTicket: str(fields.extTicket),
  }
  if (fields.id > 0) {
    payload.id = fields.id
  }

  return payload
}

// Today as d/m/Y (list-row format); the date input draft is derived via toIsoDate.
function todayDmy(): string {
  const now = new Date()

  return `${String(now.getDate()).padStart(2, '0')}/${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()}`
}

// Current wall-clock time as H:i.
function nowHi(): string {
  const now = new Date()

  return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
}

// The editable fields drive the in-cell editor; duration is server-derived.
const FIELDS: FieldDef[] = [
  { name: 'date', label: () => m.tracking_col_date(), type: 'date', required: true },
  { name: 'start', label: () => m.tracking_col_start(), type: 'text', required: true },
  { name: 'end', label: () => m.tracking_col_end(), type: 'text', required: true },
  { name: 'ticket', label: () => m.tracking_col_ticket(), type: 'text' },
  { name: 'customer', label: () => m.tracking_col_customer(), type: 'select', source: 'customers' },
  { name: 'project', label: () => m.tracking_col_project(), type: 'select', source: 'projects' },
  { name: 'activity', label: () => m.tracking_col_activity(), type: 'select', source: 'activities' },
  { name: 'description', label: () => m.tracking_col_description(), type: 'text' },
]
const FIELD_BY_KEY = new Map(FIELDS.map((field) => [field.name, field]))

const COLUMNS: { key: string; label: () => string; numeric?: boolean }[] = [
  { key: 'date', label: () => m.tracking_col_date() },
  { key: 'start', label: () => m.tracking_col_start(), numeric: true },
  { key: 'end', label: () => m.tracking_col_end(), numeric: true },
  { key: 'ticket', label: () => m.tracking_col_ticket() },
  { key: 'customer', label: () => m.tracking_col_customer() },
  { key: 'project', label: () => m.tracking_col_project() },
  { key: 'activity', label: () => m.tracking_col_activity() },
  { key: 'description', label: () => m.tracking_col_description() },
  { key: 'duration', label: () => m.tracking_col_duration(), numeric: true },
]

// Non-colour cue for the row class (WCAG 1.4.1 / 1.3.1).
function classLabel(entryClass: number): string {
  switch (entryClass) {
    case 2:
      return m.tracking_class_daybreak()
    case 4:
      return m.tracking_class_pause()
    case 8:
      return m.tracking_class_overlap()
    default:
      return ''
  }
}

// d/m/Y (list rows) or Y-m-d (draft) → Y-m-d (ISO) for display — one consistent
// date format everywhere, matching the inline editor.
function displayDate(value: string): string {
  return dmyToIso(value) ?? value
}

// One icon button in the row-actions cell — same shape for Continue/Prolong/Info/
// Delete (label drives both the accessible name and the hover tooltip).
function RowAction(props: { label: string; danger?: boolean; onClick: () => void; children: JSX.Element }): JSX.Element {
  return (
    <button
      type="button"
      class="link-button is-icon"
      classList={{ 'is-danger': props.danger }}
      aria-label={props.label}
      title={props.label}
      onClick={() => props.onClick()}
    >
      {props.children}
    </button>
  )
}

/**
 * The SolidJS work-log grid (/ui/tracking), running alongside the legacy ExtJS
 * grid. Inline cell editing reuses the shared inline-grid controller; start/end
 * accept terse times (930, 9:30a) parsed to H:i; saving a row POSTs the whole
 * entry to /tracking/save and refetches (the server recomputes duration + the
 * row class).
 */
export default function Tracking() {
  const queryClient = useQueryClient()
  const [days, setDays] = createSignal<number>(DEFAULT_DAYS)
  const entries = useQuery(() => trackingEntriesQuery(days()))
  const customers = useQuery(trackingCustomersQuery)
  const projects = useQuery(trackingProjectsQuery)
  const activities = useQuery(activitiesQuery)
  const ticketSystems = useQuery(trackingTicketSystemsQuery)
  const [summary, setSummary] = createSignal<SummaryScope[] | null>(null)
  const [bulkOpen, setBulkOpen] = createSignal(false)
  // Confirmation dialog for a destructive delete (replaces window.confirm).
  const [pendingDelete, setPendingDelete] = createSignal<TrackingEntry | null>(null)

  // Polite live region: a screen reader gets no other confirmation that a row
  // saved, deleted, or its end time changed (the row just mutates or vanishes).
  const [notice, setNotice] = createSignal('')
  function announce(message: string): void {
    // Clear, then set on the next microtask so an identical consecutive message
    // is still a DOM change and re-announces in the live region.
    setNotice('')
    queueMicrotask(() => setNotice(message))
  }
  // Assertive in-page error for delete/prolong/info failures (replaces window.alert).
  const [pageError, setPageError] = createSignal('')

  // Unsaved new rows (Add/Continue) carry a temporary negative id and render
  // above the fetched entries; they save as creates and drop on success.
  const [newRows, setNewRows] = createSignal<TrackingEntry[]>([])
  let tempId = -1
  // The grid element, captured in its ref — read for the keyboard-cursor row.
  let tableEl: HTMLTableElement | undefined
  // The grid's move handle — used to restore cell focus after a row is deleted.
  let gridHandle: GridMoveHandle | null = null
  const rows = createMemo<TrackingEntry[]>(() => [...newRows(), ...(entries.data ?? [])])
  const allProjectOptions = createMemo<NamedOption[]>(() => (projects.data ?? []).map((project) => ({ id: project.id, label: project.name })))

  // id→label maps, rebuilt only when the option list changes, so resolving a
  // relation cell is O(1) instead of an O(n) .find on every row render.
  const toLabelMap = (list: NamedOption[] | undefined): Map<number, string> => new Map((list ?? []).map((option) => [option.id, option.label]))
  const customerLabels = createMemo(() => toLabelMap(customers.data))
  const projectLabels = createMemo(() => toLabelMap(allProjectOptions()))
  const activityLabels = createMemo(() => toLabelMap(activities.data))
  const labelFrom = (map: Map<number, string>, id: number): string => (id > 0 ? (map.get(id) ?? String(id)) : '')

  const optionLookup: OptionLookup = (source: OptionSource) => {
    switch (source) {
      case 'customers':
        return customers.data ?? []
      case 'activities':
        return activities.data ?? []
      case 'projects': {
        // Cascade: while editing, show only the projects for the row's customer
        // (prod has ~1000 projects, so an unfiltered list is unusable).
        const cell = editor.editCell()
        const customerId = cell !== null ? num(editor.draftValue(cell.rowId, 'customer')) : 0
        const list = projects.data ?? []
        const scoped = customerId > 0 ? list.filter((project) => project.customer === customerId) : list

        return scoped.map((project) => ({ id: project.id, label: project.name }))
      }
      default:
        return []
    }
  }

  // Suggested start for a fresh row / empty start cell: the latest entry's end,
  // else the current wall-clock time (so a new entry continues from the last one).
  const suggestedStart = (): string => str((entries.data ?? [])[0]?.end) || nowHi()

  // On commit: ticket → derive project/customer; customer change → clear a now-
  // mismatched project; start/end → normalize a terse time (1300 → 13:00) so the
  // cell shows the fixed value immediately, not on the next refetch.
  function handleCommit(id: number, colKey: string, value: unknown): void {
    if (colKey === 'ticket') {
      const prefix = str(value).toUpperCase().trim().split(/[-:]/)[0]
      if (prefix === '') {
        return
      }
      const project = (projects.data ?? []).find((candidate) => candidate.jiraId !== '' && candidate.jiraId.toUpperCase() === prefix)
      if (project !== undefined) {
        editor.setDraftField(id, 'project', project.id)
        if (project.customer > 0) {
          editor.setDraftField(id, 'customer', project.customer)
        }
      }
    } else if (colKey === 'customer') {
      const current = (projects.data ?? []).find((project) => project.id === num(editor.draftValue(id, 'project')))
      if (current !== undefined && current.customer !== num(value)) {
        editor.setDraftField(id, 'project', 0)
      }
    } else if (colKey === 'start' || colKey === 'end') {
      const parsed = parseTime(str(value))
      if (parsed !== null && parsed !== str(value)) {
        editor.setDraftField(id, colKey, parsed)
      }
    }
  }

  const editor = createInlineGridEdit({
    rows,
    fieldFor: (colKey) => FIELD_BY_KEY.get(colKey),
    isInlineEditable: (colKey) => {
      const field = FIELD_BY_KEY.get(colKey)

      return field !== undefined && INLINE_TYPES.has(field.type)
    },
    seedDraft: (entry) => ({
      id: num(entry.id),
      date: toIsoDate(str(entry.date)),
      // An empty start (a fresh row) prefills with the suggested start so the
      // editor opens on a sensible value — but only when the user's suggest-time
      // preference is on (mirrors addEntry; respects the opt-out).
      start: str(entry.start) || (appConfig().suggestTime ? suggestedStart() : ''),
      end: str(entry.end),
      ticket: str(entry.ticket),
      customer: num(entry.customer),
      project: num(entry.project),
      activity: num(entry.activity),
      description: str(entry.description),
    }),
    saveRow: async (draft, entry) => {
      const start = parseTime(str(draft.start))
      const end = parseTime(str(draft.end))
      if (start === null || end === null) {
        throw new Error(m.tracking_invalid_time())
      }
      const isNew = num(entry.id) <= 0
      await postJson('/tracking/save', savePayload({
        id: num(entry.id),
        date: str(draft.date),
        start,
        end,
        ticket: draft.ticket,
        description: draft.description,
        customer: draft.customer,
        project: draft.project,
        activity: draft.activity,
        extTicket: entry.extTicket, // preserve the mirrored-entry key (not editable inline)
      }))
      if (isNew) {
        setNewRows((list) => list.filter((row) => num(row.id) !== num(entry.id)))
      }
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
    },
    onCommit: handleCommit,
    // Announce every successful save (auto, force, or row-leave) — the only
    // confirmation a screen-reader/low-vision user gets that the row persisted.
    onSaved: () => announce(m.tracking_saved()),
    saveErrorMessage: (caught) => (caught instanceof Error ? caught.message : m.app_load_error()),
    // Required for a bookable entry — the row auto-saves once all are valid.
    invalidFields: (draft) => {
      const invalid: string[] = []
      if (str(draft.date) === '') invalid.push('date')
      if (parseTime(str(draft.start)) === null) invalid.push('start')
      if (parseTime(str(draft.end)) === null) invalid.push('end')
      if (num(draft.customer) <= 0) invalid.push('customer')
      if (num(draft.project) <= 0) invalid.push('project')
      if (num(draft.activity) <= 0) invalid.push('activity')

      return invalid
    },
  })

  // Display value from the draft-overlaid row, so an edited-but-unsaved cell
  // shows the new value (relation ids resolved to names here, not pre-memoized,
  // because the overlay changes as the draft does).
  function displayCell(entry: TrackingEntry, colKey: string): string {
    const row = editor.overlayRow(entry)
    switch (colKey) {
      case 'date':
        return displayDate(str(row.date))
      case 'start':
        return str(row.start)
      case 'end':
        return str(row.end)
      case 'ticket':
        return str(row.ticket)
      case 'customer':
        return labelFrom(customerLabels(), num(row.customer))
      case 'project':
        return labelFrom(projectLabels(), num(row.project))
      case 'activity':
        return labelFrom(activityLabels(), num(row.activity))
      case 'description':
        return str(row.description)
      case 'duration':
        return str(entry.duration)
      default:
        return ''
    }
  }

  // The ticket-system URL for a ticket, resolved via the entry's project (mirrors
  // the ExtJS getTicketsystemUrlByTicket, with the same bugs.nr fallback).
  function ticketUrlFor(ticket: string, projectId: number): string {
    const project = (projects.data ?? []).find((candidate) => candidate.id === projectId)
    const system = project !== undefined ? (ticketSystems.data ?? []).find((candidate) => candidate.id === project.ticketSystem) : undefined
    const pattern = system !== undefined && system.ticketUrl !== '' ? system.ticketUrl : 'https://bugs.nr/%s'

    return pattern.split('%s').join(ticket)
  }

  // Cell render: ticket → a link to its ticket system; date → text + a hidden
  // row-state label; everything else → plain text.
  function cellContent(entry: TrackingEntry, colKey: string): JSX.Element {
    if (colKey === 'ticket') {
      const row = editor.overlayRow(entry)
      const ticket = str(row.ticket)

      return ticket === ''
        ? ''
        : <a class="ticket-link" href={ticketUrlFor(ticket, num(row.project))} target="_blank" rel="noopener noreferrer">{ticket}</a>
    }
    if (colKey === 'date') {
      return (
        <>
          {displayCell(entry, 'date')}
          <Show when={classLabel(entry.class) !== ''}>
            <span class="visually-hidden"> ({classLabel(entry.class)})</span>
          </Show>
        </>
      )
    }

    return displayCell(entry, colKey)
  }

  // The entry of the row holding the keyboard cursor (gridNav marks it
  // aria-current); undefined when focus is on the header or outside the grid.
  function activeEntry(): TrackingEntry | undefined {
    const rowId = tableEl?.querySelector('tr[aria-current="true"] [data-row-id]')?.getAttribute('data-row-id')
    if (rowId === null || rowId === undefined) {
      return undefined
    }

    return rows().find((entry) => String(num(entry.id)) === rowId)
  }

  // The saved entry under the cursor, else the most recent saved entry — the
  // target for Continue and Info. A new/unsaved cursor row (id <= 0) has no
  // server summary and nothing meaningful to clone, so it falls back.
  function activeOrLatestEntry(): TrackingEntry | undefined {
    const active = activeEntry()

    return active !== undefined && num(active.id) > 0 ? active : (entries.data ?? [])[0]
  }

  // Alt+I: per-customer/project/activity/ticket totals for the cursor row (or
  // the latest entry). /getSummary is a legacy endpoint that reads form params
  // ($request->request->get('id')), so it must be POSTed as form-encoded — a
  // JSON body leaves `id` null and the server answers all-zero totals.
  async function showInfo(entry?: TrackingEntry): Promise<void> {
    const target = entry ?? activeOrLatestEntry()
    if (target === undefined) {
      return
    }
    setPageError('')
    try {
      const result = JSON.parse(await postForm('/getSummary', { id: num(target.id) })) as Record<string, SummaryScope>
      setSummary([result.customer, result.project, result.activity, result.ticket].filter((scope): scope is SummaryScope => scope != null))
    } catch (caught) {
      setPageError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  // Delete is gated by an accessible confirmation dialog (no native window.confirm).
  async function confirmDelete(): Promise<void> {
    const entry = pendingDelete()
    setPendingDelete(null)
    if (entry === undefined || entry === null) {
      return
    }
    setPageError('')
    try {
      // /tracking/delete reads form params ($request->request, shared with the
      // ExtJS shell), so it must be posted as a form — not a JSON body.
      await postForm('/tracking/delete', { id: num(entry.id) })
      // Drop any pending inline draft for the now-deleted entry.
      editor.takeDraft(num(entry.id))
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
      announce(m.tracking_deleted())
      // The deleted row (and its trash button) left the DOM — restore cell focus
      // to the grid so keyboard users aren't dropped back to document start.
      queueMicrotask(() => gridHandle?.focusActive())
    } catch (caught) {
      setPageError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  // Insert a fresh new-row at the top and open it for editing.
  function pushNewRow(seed: Partial<TrackingEntry>, firstCol: string): void {
    const row: TrackingEntry = {
      id: tempId,
      date: todayDmy(),
      start: '',
      end: '',
      user: 0,
      customer: 0,
      project: 0,
      activity: 0,
      description: '',
      ticket: '',
      duration: '',
      durationMinutes: 0,
      class: 0,
      worklog: null,
      extTicket: null,
      ...seed,
    }
    tempId -= 1
    setNewRows((list) => [row, ...list])
    editor.beginEdit(num(row.id), firstCol)
  }

  // Add (Alt+A): a blank entry; suggest the start from the latest entry's end.
  function addEntry(): void {
    const previous = (entries.data ?? [])[0]
    pushNewRow({ start: appConfig().suggestTime && previous !== undefined ? str(previous.end) : '' }, 'customer')
  }

  // Continue: clone the cursor row's (or, with no cursor, the latest entry's)
  // customer/project/activity/ticket/description into a fresh blank-time row.
  function continueEntry(entry?: TrackingEntry): void {
    const source = entry ?? activeOrLatestEntry()
    if (source === undefined) {
      addEntry()

      return
    }
    pushNewRow(
      {
        customer: num(source.customer),
        project: num(source.project),
        activity: num(source.activity),
        description: str(source.description),
        ticket: str(source.ticket),
      },
      'start',
    )
  }

  // Prolong-last (Alt+P): set the latest entry's end to now and save it.
  async function prolongLast(entry?: TrackingEntry): Promise<void> {
    const base = entry ?? (entries.data ?? [])[0]
    if (base === undefined) {
      return
    }
    setPageError('')
    // Fold in any pending in-cell edit on this row so Prolong doesn't save stale
    // server values or silently discard the draft (the draft's date is already
    // ISO; the untouched row's is d/m/Y).
    const merged = editor.overlayRow(base)
    const date = str(merged.date).includes('-') ? str(merged.date) : toIsoDate(str(merged.date))
    try {
      await postJson('/tracking/save', savePayload({
        id: num(base.id),
        date,
        start: parseTime(str(merged.start)) ?? str(merged.start),
        end: nowHi(),
        ticket: merged.ticket,
        description: merged.description,
        customer: merged.customer,
        project: merged.project,
        activity: merged.activity,
        extTicket: base.extTicket,
      }))
      editor.takeDraft(num(base.id)) // the draft is now persisted — clear it
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
      announce(m.tracking_prolonged())
    } catch (caught) {
      setPageError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  const exportHref = (): string => `/export/${days()}`

  // Grid-local Alt-shortcuts (Add/Alt+A is wired via the shared header). Ignored
  // while a cell is being edited or focus is in a form control, and only the
  // keys we own are intercepted.
  function onGridShortcut(event: KeyboardEvent): void {
    if (!event.altKey || event.ctrlKey || event.metaKey || event.repeat || editor.editCell() !== null) {
      return
    }
    const target = event.target
    if (target instanceof HTMLElement && ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)) {
      return
    }
    switch (event.key.toLowerCase()) {
      case 'c':
        event.preventDefault()
        continueEntry()
        break
      case 'p':
        event.preventDefault()
        void prolongLast()
        break
      case 'r':
        event.preventDefault()
        void queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
        break
      case 'x':
        event.preventDefault()
        window.location.assign(exportHref())
        break
      case 'i':
        event.preventDefault()
        void showInfo()
        break
      default:
        break
    }
  }

  onMount(() => document.addEventListener('keydown', onGridShortcut))
  onCleanup(() => document.removeEventListener('keydown', onGridShortcut))

  return (
    <section class="tracking">
      <h2 class="visually-hidden">{m.tracking_title()}</h2>

      {/* Polite live region — save/delete/prolong confirmations for AT users. */}
      <p class="visually-hidden" role="status" aria-live="polite">{notice()}</p>
      {/* In-page error for delete/prolong/info failures (was window.alert). */}
      <Show when={pageError() !== ''}>
        <p class="form-status is-error" role="alert">{pageError()}</p>
      </Show>

      <div class="tracking-toolbar">
        <button type="button" class="primary-button is-icon" data-keyboard-add aria-keyshortcuts="Alt+A" aria-label={m.tracking_add()} title={m.tracking_add()} onClick={() => addEntry()}>
          <PlusIcon />
        </button>
        {/* Bulk entry uses ROLE_ADMIN-only presets — gate it like the (now removed) Extras page did. */}
        <Show when={canBulkEnter()}>
          <button type="button" class="action-button" onClick={() => setBulkOpen(true)}>{m.extras_title()}</button>
        </Show>
        {/* Continue / Prolong / Info moved to per-row action icons; Alt+C/P/I
            still act on the keyboard-cursor row via the global shortcut handler. */}
        <a class="action-button is-icon" href={exportHref()} aria-keyshortcuts="Alt+X" aria-label={m.tracking_export()} title={m.tracking_export()}><DownloadIcon /></a>
        <label class="tracking-days">
          <span>{m.tracking_days_label()}</span>
          <select
            value={String(days())}
            onChange={(event) => setDays(Number(event.currentTarget.value))}
          >
            <For each={DAYS_OPTIONS}>
              {(option) => <option value={String(option)}>{option === 1 ? m.tracking_days_option_one() : m.tracking_days_option({ count: String(option) })}</option>}
            </For>
          </select>
        </label>
      </div>

      {/* Inline-edit + keyboard discoverability (the only on-screen cue otherwise
          is a hover text-cursor on editable cells). */}
      <p class="tracking-hint">{m.tracking_edit_hint()}</p>

      <Show when={!entries.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table tracking-table"
            ref={(el) => { editor.setTableEl(el); tableEl = el }}
            onFocusIn={editor.onTableFocusIn}
            onFocusOut={editor.onTableFocusOut}
            use:gridNav={{
              items: rows,
              // ArrowUp off the top row hands focus to the #main-content pivot
              // (NOT the days <select>, whose own arrow keys change its value and
              // trap the cursor). From the pivot the header handles ArrowUp→nav /
              // ArrowDown→grid, so the keyboard chain stays escapable both ways.
              onExit: (direction) => { if (direction === 'up') document.getElementById('main-content')?.focus() },
              onActivate: editor.onActivate,
              moveRef: (handle) => { editor.setMoveHandle(handle); gridHandle = handle },
            }}
          >
            <thead>
              <tr>
                <For each={COLUMNS}>{(col) => <th scope="col" classList={{ numeric: col.numeric }}>{col.label()}</th>}</For>
                <th scope="col">{m.tracking_actions()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={rows()}>
                {(entry) => {
                  const id = num(entry.id)

                  return (
                    <>
                    <tr class={`tracking-row ${id <= 0 ? 'is-new' : CLASS_ROW[entry.class] ?? ''}`.trimEnd()} classList={{ 'is-dirty': editor.isDirty(id) }} aria-busy={editor.savingRows[id] ? 'true' : undefined}>
                      <For each={COLUMNS}>
                        {(col) => {
                          const editable = FIELD_BY_KEY.has(col.key)
                          const fieldType = FIELD_BY_KEY.get(col.key)?.type
                          // Single-line editors overlay a hidden ghost of the value
                          // (below) so opening one can't re-flow the auto-layout column.
                          const overlayEditor = fieldType !== undefined && INLINE_OVERLAY_TYPES.has(fieldType)

                          return (
                            <td
                              classList={{ numeric: col.numeric, 'is-editable': editable, 'is-invalid': editor.fieldInvalid(id, col.key) }}
                              data-row-id={String(id)}
                              data-col-key={col.key}
                              data-inline-editing={editor.isEditing(id, col.key) ? '' : undefined}
                              onDblClick={() => { if (editable) editor.beginEdit(id, col.key) }}
                            >
                              <Show
                                when={editor.isEditing(id, col.key)}
                                fallback={cellContent(entry, col.key)}
                              >
                                {/* Hidden ghost holds the column width so the overlaying
                                    single-line editor can't make the table re-flow. */}
                                <Show when={overlayEditor}>
                                  <span class="inline-ghost" aria-hidden="true">{cellContent(entry, col.key)}</span>
                                </Show>
                                <InlineEditor
                                  field={FIELD_BY_KEY.get(col.key)!}
                                  label={col.label()}
                                  initial={editor.draftValue(id, col.key) ?? ''}
                                  seed={editor.seedChar()}
                                  options={optionLookup}
                                  onCommit={editor.commitCell}
                                  onCancel={editor.cancelCell}
                                />
                              </Show>
                            </td>
                          )
                        }}
                      </For>
                      {/* data-row-id (no data-col-key → not inline-editable) keeps focus
                          "inside the row" so clicking Delete isn't read as a row-leave. */}
                      <td class="tracking-row-actions" data-row-id={String(id)}>
                        <div class="row-actions">
                          {/* Per-row Continue / Prolong / Info — only for saved entries. */}
                          <Show when={id > 0}>
                            <RowAction label={m.tracking_continue()} onClick={() => continueEntry(entry)}><ContinueIcon /></RowAction>
                            <RowAction label={m.tracking_prolong()} onClick={() => void prolongLast(entry)}><ProlongIcon /></RowAction>
                            <RowAction label={m.tracking_info()} onClick={() => void showInfo(entry)}><InfoIcon /></RowAction>
                          </Show>
                          <RowAction label={m.admin_delete()} danger onClick={() => setPendingDelete(entry)}><TrashIcon /></RowAction>
                          {/* Force a full save (shows the full error if it fails). Always rendered as
                              the last action in a reserved slot — only its visibility toggles — so the
                              Delete icon never shifts when a row becomes dirty. */}
                          <button type="button" class="link-button is-icon is-unsaved" classList={{ 'action-slot-hidden': !editor.isDirty(id) }} aria-label={m.app_save()} title={m.app_save()} onClick={() => void editor.flushRow(id)}>
                            <DiskIcon />
                          </button>
                        </div>
                      </td>
                    </tr>
                    {/* Save error gets its own full-width row beneath the row. */}
                    <Show when={editor.rowErrors[id]}>
                      <tr class="row-error">
                        <td colspan={COLUMNS.length + 1}>
                          <span role="alert" class="form-status is-error">{editor.rowErrors[id]}</span>
                        </td>
                      </tr>
                    </Show>
                    </>
                  )
                }}
              </For>
            </tbody>
          </table>
        </div>

        <Show when={entries.isLoading}>
          <p class="tracking-loading">{m.app_loading()}</p>
        </Show>
        <Show when={rows().length === 0 && !entries.isLoading}>
          <div class="tracking-empty">
            <p>{m.tracking_empty()}</p>
            <button type="button" class="primary-button" onClick={() => addEntry()}>{m.tracking_empty_cta()}</button>
          </div>
        </Show>

        {/* Legend for the colour-coded row borders — the colour alone is not an
            accessible cue, so each swatch is paired with its label. */}
        <Show when={rows().length > 0}>
          <p class="tracking-legend">
            <span class="visually-hidden">{m.tracking_legend_title()}: </span>
            <span class="tracking-legend-item is-daybreak">{m.tracking_class_daybreak()}</span>
            <span class="tracking-legend-item is-pause">{m.tracking_class_pause()}</span>
            <span class="tracking-legend-item is-overlap">{m.tracking_class_overlap()}</span>
          </p>
        </Show>
      </Show>

      <PageDialog open={summary() !== null} onClose={() => setSummary(null)} title={m.tracking_info()}>
        <div class="table-scroll">
          <table class="data-table tracking-summary">
            <thead>
              <tr>
                <th scope="col">{m.tracking_summary_scope()}</th>
                <th scope="col" class="numeric">{m.tracking_summary_own()}</th>
                <th scope="col" class="numeric">{m.tracking_summary_total()}</th>
                <th scope="col" class="numeric">{m.tracking_summary_estimation()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={summary() ?? []}>
                {(scope) => (
                  <tr>
                    <th scope="row">{scope.name === '' ? scope.scope : scope.name}</th>
                    <td class="numeric">{formatMinutes(scope.own)}</td>
                    <td class="numeric">{formatMinutes(scope.total)}</td>
                    <td class="numeric">{scope.estimation > 0 ? formatMinutes(scope.estimation) : '—'}</td>
                  </tr>
                )}
              </For>
            </tbody>
          </table>
        </div>
      </PageDialog>

      {/* Bulk-created entries may fall outside the current days range;
          refetch so any that land in view appear. */}
      <PageDialog open={bulkOpen()} onClose={() => setBulkOpen(false)} title={m.extras_title()}>
        <BulkEntryForm onSaved={() => void queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })} />
      </PageDialog>

      {/* Accessible delete confirmation (replaces native window.confirm). */}
      <PageDialog open={pendingDelete() !== null} onClose={() => setPendingDelete(null)} title={m.tracking_delete_title()}>
        <p class="dialog-body">{m.tracking_delete_body()}</p>
        <div class="form-actions">
          <button type="button" class="primary-button is-danger" onClick={() => void confirmDelete()}>{m.tracking_delete_confirm()}</button>
          <button type="button" class="action-button" onClick={() => setPendingDelete(null)}>{m.admin_cancel()}</button>
        </div>
      </PageDialog>
    </section>
  )
}
