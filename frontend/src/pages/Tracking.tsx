import { Dialog } from '@ark-ui/solid/dialog'
import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createMemo, createSignal, For, onCleanup, onMount, Show, type JSX } from 'solid-js'
import { Portal } from 'solid-js/web'

import { apiErrorMessage, postJson } from '../api/client'
import { activitiesQuery, trackingCustomersQuery, trackingEntriesQuery, trackingProjectsQuery, trackingTicketSystemsQuery, type NamedOption, type SummaryScope, type TrackingEntry } from '../api/queries'
import { appConfig } from '../config'
import type { FieldDef, OptionLookup, OptionSource } from '../admin/types'
import { gridNav } from '../lib/gridNavigation'
import { createInlineGridEdit, InlineEditor, INLINE_TYPES } from '../lib/inlineGridEdit'
import { parseTime, toIsoDate } from '../lib/timeParse'
import { m } from '../paraglide/messages.js'

// Register the directive with the JSX namespace (Solid tree-shakes unused imports).
void gridNav

const DAYS_OPTIONS = [1, 3, 7, 35] as const
const DEFAULT_DAYS = 3
const ENTRIES_KEY = 'tracking-entries'

// Server-computed EntryClass → row modifier, mirroring the ExtJS row borders.
const CLASS_ROW: Record<number, string> = { 2: 'is-daybreak', 4: 'is-pause', 8: 'is-overlap' }

const num = (value: unknown): number => Number(value ?? 0)
const str = (value: unknown): string => (value === undefined || value === null ? '' : String(value))

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

// Minutes → H:i (for the summary popup totals).
function fmtMinutes(minutes: number): string {
  const sign = minutes < 0 ? '-' : ''
  const abs = Math.abs(minutes)

  return `${sign}${Math.floor(abs / 60)}:${String(abs % 60).padStart(2, '0')}`
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

function nameOf(list: NamedOption[] | undefined, id: number): string {
  if (id <= 0 || list === undefined) {
    return ''
  }

  return list.find((option) => option.id === id)?.label ?? String(id)
}

// d/m/Y (list rows) or Y-m-d (date-input draft) → d/m/Y for display.
function displayDate(value: string): string {
  const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)

  return iso !== null ? `${iso[3]}/${iso[2]}/${iso[1]}` : value
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

  // Unsaved new rows (Add/Continue) carry a temporary negative id and render
  // above the fetched entries; they save as creates and drop on success.
  const [newRows, setNewRows] = createSignal<TrackingEntry[]>([])
  let tempId = -1
  const rows = createMemo<TrackingEntry[]>(() => [...newRows(), ...(entries.data ?? [])])
  const allProjectOptions = createMemo<NamedOption[]>(() => (projects.data ?? []).map((project) => ({ id: project.id, label: project.name })))

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

  // Derive project (+ its customer) from a ticket prefix matching a project's
  // jiraId; clear a now-mismatched project when the customer changes.
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
      start: str(entry.start),
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
      }))
      if (isNew) {
        setNewRows((list) => list.filter((row) => num(row.id) !== num(entry.id)))
      }
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
    },
    onCommit: handleCommit,
    saveErrorMessage: (caught) => (caught instanceof Error ? caught.message : m.app_load_error()),
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
        return nameOf(customers.data, num(row.customer))
      case 'project':
        return nameOf(allProjectOptions(), num(row.project))
      case 'activity':
        return nameOf(activities.data, num(row.activity))
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

  // Alt+I: per-customer/project/activity/ticket totals for an entry (or the latest).
  async function showInfo(entry?: TrackingEntry): Promise<void> {
    const target = entry ?? (entries.data ?? [])[0]
    if (target === undefined) {
      return
    }
    try {
      const result = await postJson<Record<string, SummaryScope>>('/getSummary', { id: num(target.id) })
      setSummary([result.customer, result.project, result.activity, result.ticket].filter((scope): scope is SummaryScope => scope != null))
    } catch (caught) {
      window.alert(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  async function removeEntry(entry: TrackingEntry): Promise<void> {
    if (!window.confirm(m.admin_delete_confirm())) {
      return
    }
    try {
      await postJson('/tracking/delete', { id: num(entry.id) })
      // Drop any pending inline draft for the now-deleted entry.
      editor.takeDraft(num(entry.id))
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
    } catch (caught) {
      window.alert(apiErrorMessage(caught, m.app_load_error()))
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

  // Continue: clone the latest entry's customer/project/activity/ticket/description
  // into a fresh blank-time row.
  function continueEntry(): void {
    const previous = (entries.data ?? [])[0]
    if (previous === undefined) {
      addEntry()

      return
    }
    pushNewRow(
      {
        customer: num(previous.customer),
        project: num(previous.project),
        activity: num(previous.activity),
        description: str(previous.description),
        ticket: str(previous.ticket),
      },
      'start',
    )
  }

  // Prolong-last (Alt+P): set the latest entry's end to now and save it.
  async function prolongLast(): Promise<void> {
    const first = (entries.data ?? [])[0]
    if (first === undefined) {
      return
    }
    try {
      await postJson('/tracking/save', savePayload({
        id: num(first.id),
        date: toIsoDate(str(first.date)),
        start: str(first.start),
        end: nowHi(),
        ticket: first.ticket,
        description: first.description,
        customer: first.customer,
        project: first.project,
        activity: first.activity,
      }))
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
    } catch (caught) {
      window.alert(apiErrorMessage(caught, m.app_load_error()))
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

  let daysSelectEl: HTMLSelectElement | undefined

  return (
    <section class="tracking">
      <h2 class="visually-hidden">{m.tracking_title()}</h2>

      <div class="tracking-toolbar">
        <button type="button" class="primary-button" data-keyboard-add aria-keyshortcuts="Alt+A" onClick={() => addEntry()}>
          {m.tracking_add()}
        </button>
        <button type="button" class="action-button" onClick={() => continueEntry()} aria-keyshortcuts="Alt+C">{m.tracking_continue()}</button>
        <button type="button" class="action-button" onClick={() => void prolongLast()} aria-keyshortcuts="Alt+P">{m.tracking_prolong()}</button>
        <button type="button" class="action-button" onClick={() => void showInfo()} aria-keyshortcuts="Alt+I">{m.tracking_info()}</button>
        <a class="action-button" href={exportHref()} aria-keyshortcuts="Alt+X">{m.tracking_export()}</a>
        <label class="tracking-days">
          <span>{m.tracking_days_label()}</span>
          <select
            ref={(el) => { daysSelectEl = el }}
            value={String(days())}
            onChange={(event) => setDays(Number(event.currentTarget.value))}
          >
            <For each={DAYS_OPTIONS}>
              {(option) => <option value={String(option)}>{m.tracking_days_option({ count: String(option) })}</option>}
            </For>
          </select>
        </label>
      </div>

      <Show when={!entries.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table tracking-table"
            ref={(el) => { editor.setTableEl(el) }}
            onFocusIn={editor.onTableFocusIn}
            onFocusOut={editor.onTableFocusOut}
            use:gridNav={{
              items: rows,
              onExit: (direction) => { if (direction === 'up') daysSelectEl?.focus() },
              onActivate: editor.onActivate,
              moveRef: (handle) => { editor.setMoveHandle(handle) },
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
                    <tr class={`tracking-row ${id <= 0 ? 'is-new' : CLASS_ROW[entry.class] ?? ''}`.trimEnd()} aria-busy={editor.savingRows[id] ? 'true' : undefined}>
                      <For each={COLUMNS}>
                        {(col) => {
                          const editable = FIELD_BY_KEY.has(col.key)

                          return (
                            <td
                              classList={{ numeric: col.numeric, 'is-editable': editable }}
                              data-row-id={String(id)}
                              data-col-key={col.key}
                              data-inline-editing={editor.isEditing(id, col.key) ? '' : undefined}
                              aria-label={editor.isEditing(id, col.key) ? col.label() : undefined}
                              onDblClick={() => { if (editable) editor.beginEdit(id, col.key) }}
                            >
                              <Show
                                when={editor.isEditing(id, col.key)}
                                fallback={cellContent(entry, col.key)}
                              >
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
                        <button type="button" class="link-button is-danger" onClick={() => void removeEntry(entry)}>
                          <svg class="action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                          {m.admin_delete()}
                        </button>
                        <Show when={editor.rowErrors[id]}>
                          <span role="alert" class="form-status is-error">{editor.rowErrors[id]}</span>
                        </Show>
                      </td>
                    </tr>
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
          <p class="tracking-empty">{m.tracking_empty()}</p>
        </Show>
      </Show>

      <Show when={summary() !== null}>
        <Dialog.Root open onOpenChange={(details) => { if (!details.open) setSummary(null) }} lazyMount unmountOnExit>
          <Portal>
            <Dialog.Backdrop class="modal-backdrop" />
            <Dialog.Positioner class="modal-positioner">
              <Dialog.Content class="modal">
                <header class="modal-page-header">
                  <Dialog.Title class="modal-page-title">{m.tracking_info()}</Dialog.Title>
                  <Dialog.CloseTrigger class="modal-close" aria-label={m.dialog_close()}>×</Dialog.CloseTrigger>
                </header>
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
                          <td class="numeric">{fmtMinutes(scope.own)}</td>
                          <td class="numeric">{fmtMinutes(scope.total)}</td>
                          <td class="numeric">{scope.estimation > 0 ? fmtMinutes(scope.estimation) : '—'}</td>
                        </tr>
                      )}
                    </For>
                  </tbody>
                </table>
              </Dialog.Content>
            </Dialog.Positioner>
          </Portal>
        </Dialog.Root>
      </Show>
    </section>
  )
}
