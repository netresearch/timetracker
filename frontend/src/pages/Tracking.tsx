import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createMemo, createSignal, For, onCleanup, onMount, Show, type JSX } from 'solid-js'

import { apiErrorMessage, postForm, postJson, ValidationError } from '../api/client'
import { activitiesQuery, ENTRIES_KEY, trackingCustomersQuery, trackingEntriesQuery, trackingProjectsQuery, trackingTicketSystemsQuery, upsertSavedEntry, type NamedOption, type SavedEntryResult, type SummaryScope, type TrackingEntry } from '../api/queries'
import { appConfig, canBulkEnter } from '../config'
import type { FieldDef, OptionLookup, OptionSource } from '../admin/types'
import { num, str } from '../lib/coerce'
import { formatUserDate } from '../lib/dateFormat'
import { formatMinutes } from '../lib/format'
import { gridNav, type GridMoveHandle } from '../lib/gridNavigation'
import { chipValues, createInlineGridEdit, fieldSelectOptions, InlineEditor, INLINE_OVERLAY_TYPES, INLINE_TYPES, ReadonlyChips } from '../lib/inlineGridEdit'
import { ChipSelect } from '../lib/chipSelect'
import { registerCommands } from '../lib/commandPalette'
import { getTrackingDays, setTrackingDays } from '../lib/trackingDaysPref'
import { ContinueIcon, DiskIcon, DownloadIcon, InfoIcon, PlusIcon, ProlongIcon, RefreshIcon, ResetIcon, TrashIcon } from '../lib/icons'
import { BulkEntryForm } from '../components/BulkEntryForm'
import { PageDialog } from '../components/PageDialog'
import { sessionExpired } from '../lib/session'
import { updateWorktime } from '../header'
import { dmyToIso, parseTime, toIsoDate } from '../lib/timeParse'
import { m } from '../paraglide/messages.js'

// Register the directive with the JSX namespace (Solid tree-shakes unused imports).
void gridNav

const DAYS_OPTIONS = [1, 3, 7, 35] as const
const DEFAULT_DAYS = 3
// The freetext day-range accepts any whole number, capped at a year so a stray
// keystroke (or a stale localStorage value) can't ask the grid for everything.
const MAX_DAYS = 366
// Widen target for the range-aware empty state (the largest preset window).
const WIDEN_DAYS = 35

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

// Add minutes to an H:i time, capped at 23:59 (a single entry can't cross midnight).
function addMinutes(hi: string, mins: number): string {
  const [hh, mm] = hi.split(':')
  const h = Number(hh)
  const m = Number(mm)
  if (Number.isNaN(h) || Number.isNaN(m)) {
    return ''
  }
  const total = Math.min(h * 60 + m + mins, 23 * 60 + 59)

  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

// The editable fields drive the in-cell editor; duration is server-derived.
const FIELDS: FieldDef[] = [
  { name: 'date', label: () => m.tracking_col_date(), type: 'date', required: true },
  { name: 'start', label: () => m.tracking_col_start(), type: 'text', required: true },
  { name: 'end', label: () => m.tracking_col_end(), type: 'text', required: true },
  { name: 'ticket', label: () => m.tracking_col_ticket(), type: 'text' },
  { name: 'customer', label: () => m.tracking_col_customer(), type: 'select', source: 'customers', required: true },
  { name: 'project', label: () => m.tracking_col_project(), type: 'select', source: 'projects', required: true },
  { name: 'activity', label: () => m.tracking_col_activity(), type: 'select', source: 'activities', required: true },
  { name: 'description', label: () => m.tracking_col_description(), type: 'text' },
]
const FIELD_BY_KEY = new Map(FIELDS.map((field) => [field.name, field]))

const COLUMNS: { key: string; label: () => string; numeric?: boolean }[] = [
  { key: 'date', label: () => m.tracking_col_date() },
  { key: 'start', label: () => m.tracking_col_start(), numeric: true },
  { key: 'end', label: () => m.tracking_col_end(), numeric: true },
  { key: 'ticket', label: () => m.tracking_col_ticket() },
  // Read-only: the external key the backend mirrored onto the internal Jira
  // ticket (set during save, not user-editable) — so it isn't a FIELD.
  { key: 'extTicket', label: () => m.tracking_col_ext_ticket() },
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

// d/m/Y (list rows) or Y-m-d (draft) → the user's chosen display format (ISO by
// default). The wire format and the inline editor stay ISO; this is display-only.
function displayDate(value: string): string {
  return formatUserDate(dmyToIso(value) ?? value)
}

// One icon button in the row-actions cell — same shape for Continue/Prolong/Info/
// Delete (label drives both the accessible name and the hover tooltip).
function RowAction(props: { label: string; danger?: boolean; keyshortcut?: string; onClick: () => void; children: JSX.Element }): JSX.Element {
  return (
    <button
      type="button"
      class="link-button is-icon"
      classList={{ 'is-danger': props.danger }}
      aria-label={props.label}
      aria-keyshortcuts={props.keyshortcut}
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
  // The day range persists across remounts/logins (client-side, like the theme).
  const [days, setDays] = createSignal<number>(Math.min(getTrackingDays(DEFAULT_DAYS), MAX_DAYS))
  // Preset-range combobox: the menu always lists every preset (unlike a native
  // datalist, which filters by the typed value and forced the user to clear the
  // field to pick another range). Free typing still applies a custom day count.
  const [daysMenuOpen, setDaysMenuOpen] = createSignal(false)
  let daysComboRef: HTMLDivElement | undefined
  onMount(() => {
    const onDocPointer = (event: PointerEvent): void => {
      if (daysComboRef !== undefined && !daysComboRef.contains(event.target as Node)) {
        setDaysMenuOpen(false)
      }
    }
    document.addEventListener('pointerdown', onDocPointer)
    onCleanup(() => document.removeEventListener('pointerdown', onDocPointer))
  })
  const entries = useQuery(() => trackingEntriesQuery(days()))
  const customers = useQuery(trackingCustomersQuery)
  const projects = useQuery(trackingProjectsQuery)
  const activities = useQuery(activitiesQuery)
  const ticketSystems = useQuery(trackingTicketSystemsQuery)
  const [summary, setSummary] = createSignal<SummaryScope[] | null>(null)
  const [bulkOpen, setBulkOpen] = createSignal(false)
  // Confirmation dialog for a destructive delete (replaces window.confirm).
  const [pendingDelete, setPendingDelete] = createSignal<TrackingEntry | null>(null)

  // Refetch the worklog grid AND refresh the server header's day/week/month
  // totals. The header loads those once on init, so without this they go stale
  // after a save / edit / delete (and the refresh button) until a full page
  // reload (#446). The two are independent (the mutation already hit the DB),
  // so run them in parallel. updateWorktime swallows its own errors.
  const refreshWorklog = async (): Promise<void> => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] }),
      updateWorktime(),
    ])
  }

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

  // Visible, auto-dismissing save confirmation (mirrors AdminCrudShell.flashNotice):
  // the polite live region above tells AT users; sighted users get a brief toast.
  const [savedNotice, setSavedNotice] = createSignal('')
  let noticeTimer: ReturnType<typeof setTimeout> | undefined
  function flashNotice(message: string): void {
    setSavedNotice(message)
    clearTimeout(noticeTimer)
    noticeTimer = setTimeout(() => setSavedNotice(''), 3000)
  }
  onCleanup(() => clearTimeout(noticeTimer))

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

  // The PICKER offers only bookable (active) customers/projects, plus the row's
  // CURRENT value even if it's since been deactivated — so editing an existing
  // entry whose customer/project was deactivated keeps it visible/selectable and
  // never silently drops it. Activities have no active concept.
  const optionLookup: OptionLookup = (source: OptionSource) => {
    const cell = editor.editCell()
    const currentOf = (field: string): number => (cell !== null ? num(editor.draftValue(cell.rowId, field)) : 0)
    const bookable = <T extends { id: number; active?: boolean }>(list: T[], keepId: number): T[] =>
      list.filter((option) => option.active !== false || option.id === keepId)

    switch (source) {
      case 'customers':
        return bookable(customers.data ?? [], currentOf('customer'))
      case 'activities':
        return activities.data ?? []
      case 'projects': {
        // Cascade: while editing, show only the projects for the row's customer
        // (prod has ~1000 projects, so an unfiltered list is unusable).
        const customerId = currentOf('customer')
        const list = projects.data ?? []
        const scoped = customerId > 0 ? list.filter((project) => project.customer === customerId) : list

        return bookable(scoped, currentOf('project')).map((project) => ({ id: project.id, label: project.name }))
      }
      default:
        return []
    }
  }

  // Read-mode chip labels resolve against the FULL option set (incl. inactive), so
  // an existing entry's deactivated customer/project still renders its name — the
  // active-only filtering above applies ONLY to the open editor's picker.
  const readOptionLookup: OptionLookup = (source: OptionSource) => {
    switch (source) {
      case 'projects':
        return allProjectOptions()
      case 'customers':
        return customers.data ?? []
      case 'activities':
        return activities.data ?? []
      default:
        return []
    }
  }

  // Suggested start for a fresh row / empty start cell: continue from the latest
  // entry's end ONLY if that entry is from TODAY; otherwise a fresh day starts at
  // the current wall-clock time, not yesterday's (or older) last end.
  const suggestedStart = (): string => {
    const latest = (entries.data ?? [])[0]
    if (latest !== undefined && dmyToIso(str(latest.date)) === dmyToIso(todayDmy())) {
      return str(latest.end) || nowHi()
    }

    return nowHi()
  }

  // On commit: ticket → derive project/customer; customer change → clear a now-
  // mismatched project; start/end → normalize a terse time (1300 → 13:00) so the
  // cell shows the fixed value immediately, not on the next refetch.
  function handleCommit(id: number, colKey: string, value: unknown): void {
    if (colKey === 'ticket') {
      const prefix = str(value).toUpperCase().trim().split(/[-:]/)[0] ?? ''
      if (prefix === '') {
        return
      }
      // jiraId is a comma/space-separated list of allowed prefixes (the backend
      // splits it the same way in validateTicketPrefix), so match membership —
      // an exact === missed multi-prefix projects, so an external ticket like
      // DHLSUP-1 derived the wrong/no project and the save was rejected (#453).
      const project = (projects.data ?? []).find(
        (candidate) => candidate.jiraId !== '' && candidate.jiraId.toUpperCase().split(/[\s,]+/).includes(prefix),
      )
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
    seedDraft: (entry) => {
      // A fresh row prefills start with the suggested start, then end with start +
      // the per-user minimum (minEntryDuration minutes) — so a new entry opens with a
      // sensible default span. Both respect the suggest-time opt-out; a 0 minimum (or
      // an already-set value) leaves end blank.
      const start = str(entry.start) || (appConfig().suggestTime ? suggestedStart() : '')
      const minMinutes = appConfig().minEntryDuration

      return {
        id: num(entry.id),
        date: toIsoDate(str(entry.date)),
        start,
        end: str(entry.end) || (start !== '' && appConfig().suggestTime && minMinutes > 0 ? addMinutes(start, minMinutes) : ''),
        ticket: str(entry.ticket),
        customer: num(entry.customer),
        project: num(entry.project),
        activity: num(entry.activity),
        description: str(entry.description),
      }
    },
    saveRow: async (draft, entry) => {
      const start = parseTime(str(draft.start))
      const end = parseTime(str(draft.end))
      if (start === null || end === null) {
        throw new ValidationError(m.tracking_invalid_time())
      }
      // start/end are zero-padded "HH:mm", so a string compare orders them. The
      // backend rejects start >= end too, but its message isn't localized (#441)
      // and only fires after a round-trip — so catch it here, in the user's
      // language, before the request.
      if (start >= end) {
        throw new ValidationError(m.tracking_time_order())
      }
      const isNew = num(entry.id) <= 0
      const saved = await postJson<SavedEntryResult>('/tracking/save', savePayload({
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
      // Land the saved entry in the cache from the 200 itself, so it's in the grid
      // BEFORE — and independent of — the reconciling refetch below. A new row drops
      // from newRows here; without the upsert, a refetch that errors (session expiry,
      // issue #408) would leave the entry in neither newRows nor entries.data and the
      // user's just-saved work would vanish.
      upsertSavedEntry(queryClient, saved.result)
      if (isNew) {
        setNewRows((list) => list.filter((row) => num(row.id) !== num(entry.id)))
      }
      await refreshWorklog()
    },
    onCommit: handleCommit,
    // Confirm every successful save (auto, force, or row-leave): announce to AT
    // users via the live region AND flash a brief visible toast for sighted users.
    onSaved: () => { announce(m.tracking_saved()); flashNotice(m.tracking_saved()) },
    saveErrorMessage: (caught) => apiErrorMessage(caught, m.app_load_error()),
    // Required for a bookable entry — the row auto-saves once all are valid.
    invalidFields: (draft) => {
      const invalid: string[] = []
      // The date must be a complete ISO yyyy-mm-dd, not merely non-empty, so a
      // half-typed manual edit ('2026-06') can't auto-save garbage.
      if (!/^\d{4}-\d{2}-\d{2}$/.test(str(draft.date))) invalid.push('date')
      if (parseTime(str(draft.start)) === null) invalid.push('start')
      if (parseTime(str(draft.end)) === null) invalid.push('end')
      if (num(draft.customer) <= 0) invalid.push('customer')
      if (num(draft.project) <= 0) invalid.push('project')
      if (num(draft.activity) <= 0) invalid.push('activity')

      return invalid
    },
  })

  // A row counts as "unsaved" — and so shows the disk (force-save) + reset
  // actions — when it has real pending edits OR when it's a brand-new row
  // (id <= 0) that was never persisted. This drives only the visual cue; the
  // save-gating dirtiness in the controller is unchanged, so leaving a pristine
  // new row still never triggers a spurious save.
  const isUnsaved = (id: number): boolean => id <= 0 || editor.isDirty(id)

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
      case 'extTicket':
        return str(row.extTicket)
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
    // Relation columns read as chips (matching the admin grid), not free text.
    const field = FIELD_BY_KEY.get(colKey)
    if (field !== undefined && (field.type === 'select' || field.type === 'multiselect')) {
      return <ReadonlyChips values={chipValues((editor.overlayRow(entry) as unknown as Record<string, unknown>)[colKey])} options={fieldSelectOptions(field, readOptionLookup)} />
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

  // The most recent saved entry (entries are returned newest-first) — the only
  // row whose end Prolong may rewrite to now without corrupting an older span.
  const isLatestEntry = (entry: TrackingEntry): boolean => {
    const latest = (entries.data ?? [])[0]

    return latest !== undefined && num(latest.id) === num(entry.id)
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
      await refreshWorklog()
      announce(m.tracking_deleted())
      // The deleted row (and its trash button) left the DOM — restore cell focus
      // to the grid so keyboard users aren't dropped back to document start.
      queueMicrotask(() => gridHandle?.focusActive())
    } catch (caught) {
      setPageError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  // Reset (discard): throw away a row's unsaved edits and restore the saved
  // state. A brand-new row has no DB state, so it's removed entirely (mirrors the
  // create-success drop in saveRow) — never POSTed to /tracking/delete.
  function resetEntry(entry: TrackingEntry): void {
    const id = num(entry.id)
    editor.resetRow(id)
    if (id <= 0) {
      setNewRows((list) => list.filter((row) => num(row.id) !== id))
      // The removed row left the DOM — keep keyboard focus inside the grid.
      queueMicrotask(() => gridHandle?.focusActive())
    }
    announce(m.tracking_reset_done())
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

  // Add (Alt+A): a blank entry. suggestedStart inherits the latest entry's end
  // ONLY when that entry is from today; on a fresh day it starts at the current
  // time, not yesterday's (or older) last end.
  function addEntry(): void {
    pushNewRow({ start: appConfig().suggestTime ? suggestedStart() : '' }, 'customer')
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
    const start = parseTime(str(merged.start)) ?? str(merged.start)
    // Prolong sets the end to "now", which only makes sense once now is at/after the
    // entry's start. On a not-yet-started (e.g. future-dated) entry that would write a
    // backward span — abort rather than save a meaningless edit with the old end.
    if (nowHi() < start) {
      return
    }
    const end = nowHi()
    try {
      const saved = await postJson<SavedEntryResult>('/tracking/save', savePayload({
        id: num(base.id),
        date,
        start,
        end,
        ticket: merged.ticket,
        description: merged.description,
        customer: merged.customer,
        project: merged.project,
        activity: merged.activity,
        extTicket: base.extTicket,
      }))
      upsertSavedEntry(queryClient, saved.result) // keep the prolonged row if the refetch fails
      editor.takeDraft(num(base.id)) // the draft is now persisted — clear it
      await refreshWorklog()
      announce(m.tracking_prolonged())
    } catch (caught) {
      setPageError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  // Change the day range and persist the choice (so it survives a remount/login).
  // Whole numbers only, floored at one day and capped at MAX_DAYS — the freetext
  // input lets a user type anything, so the clamp lives here, the single setter.
  function applyDays(value: number): void {
    const clamped = Math.min(Math.max(1, Math.trunc(value)), MAX_DAYS)
    if (!Number.isFinite(clamped)) {
      return
    }
    setDays(clamped)
    setTrackingDays(clamped)
  }

  const exportHref = (): string => `/export/${days()}`

  // Reload the worklog entries and the header totals (the Alt+R shortcut and the
  // toolbar refresh button share this). The reference/option lookups carry a long
  // staleTime and aren't force-refreshed on a routine reload.
  function refreshEntries(): void {
    void refreshWorklog()
    announce(m.tracking_refreshed())
  }

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
        // Prolong always targets the latest entry (not the cursor row): it rewrites
        // the end to "now", which would corrupt an older focused entry. Unlike
        // Continue/Info, this action mutates, so it stays latest-only.
        void prolongLast()
        break
      case 'r':
        event.preventDefault()
        refreshEntries()
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

  // A row's Alt-shortcut hint is only truthful while no cell is being edited:
  // onGridShortcut() ignores Alt+C/P/I during editing, so advertising the
  // shortcut then (aria-keyshortcuts + the Alt overlay badge) would mislead.
  const rowShortcut = (key: string): string | undefined => (editor.editCell() === null ? key : undefined)

  // Surface the worklog actions in the Ctrl/⌘+K command palette while this page
  // is mounted — the discoverable home for every action (no shortcut to memorise).
  const wl = (): string => m.cmd_group_worklog()
  onCleanup(registerCommands([
    { id: 'wl-add', group: wl, label: () => m.help_sc_add(), shortcut: 'Alt+A', run: () => addEntry() },
    { id: 'wl-continue', group: wl, label: () => m.help_sc_continue(), shortcut: 'Alt+C', run: () => continueEntry() },
    { id: 'wl-prolong', group: wl, label: () => m.help_sc_prolong(), shortcut: 'Alt+P', run: () => void prolongLast() },
    { id: 'wl-info', group: wl, label: () => m.help_sc_info(), shortcut: 'Alt+I', run: () => void showInfo() },
    { id: 'wl-refresh', group: wl, label: () => m.help_sc_refresh(), shortcut: 'Alt+R', run: () => refreshEntries() },
    { id: 'wl-export', group: wl, label: () => m.help_sc_export(), shortcut: 'Alt+X', run: () => window.location.assign(exportHref()) },
    ...(canBulkEnter() ? [{ id: 'wl-bulk', group: wl, label: () => m.cmd_bulk(), run: () => setBulkOpen(true) }] : []),
    { id: 'wl-days-today', group: wl, label: () => m.cmd_days_today(), run: () => applyDays(1) },
    { id: 'wl-days-week', group: wl, label: () => m.cmd_days_week(), run: () => applyDays(7) },
    { id: 'wl-days-5weeks', group: wl, label: () => m.cmd_days_5weeks(), run: () => applyDays(35) },
  ]))

  return (
    <section class="tracking">
      <h2 class="visually-hidden">{m.tracking_title()}</h2>

      {/* Polite live region — save/delete/prolong confirmations for AT users. */}
      <p class="visually-hidden" role="status" aria-live="polite">{notice()}</p>
      {/* In-page error for delete/prolong/info failures (was window.alert). */}
      <Show when={pageError() !== ''}>
        <p class="form-status is-error" role="alert">{pageError()}</p>
      </Show>
      {/* Visible, auto-dismissing save confirmation (reuses the admin .is-ok cue).
          Purely visual: aria-hidden so AT users aren't told twice — the polite
          live region above already announces the save. */}
      <Show when={savedNotice() !== ''}>
        <p class="form-status is-ok" aria-hidden="true">{savedNotice()}</p>
      </Show>

      <div class="tracking-toolbar">
        <button type="button" class="primary-button is-icon" data-keyboard-add aria-keyshortcuts="Alt+A" aria-label={m.tracking_add()} title={m.tracking_add()} onClick={() => addEntry()}>
          <PlusIcon />
        </button>
        {/* Bulk entry uses ROLE_ADMIN-only presets — gate it like the (now removed) Extras page did. */}
        <Show when={canBulkEnter()}>
          <button type="button" class="action-button" onClick={() => setBulkOpen(true)}>{m.extras_title()}</button>
        </Show>
        {/* Reload the entries (Alt+R). Outside the admin gate — every user gets it. */}
        <button type="button" class="action-button is-icon" aria-keyshortcuts="Alt+R" aria-label={m.tracking_refresh()} title={m.tracking_refresh()} onClick={() => refreshEntries()}>
          <RefreshIcon />
        </button>
        {/* Continue / Prolong / Info moved to per-row action icons; Alt+C/P/I
            still act on the keyboard-cursor row via the global shortcut handler. */}
        <a class="action-button is-icon" href={exportHref()} aria-keyshortcuts="Alt+X" aria-label={m.tracking_export()} title={m.tracking_export()}><DownloadIcon /></a>
        {/* Freetext + always-full preset menu: type any whole number of days
            (applyDays clamps + persists), or pick a preset — the menu always lists
            ALL presets regardless of what's typed, so switching ranges never needs
            clearing the field first. */}
        <div class="tracking-days">
          <span id="tracking-days-lbl">{m.tracking_days_label()}</span>
          <div class="days-combo" ref={(el) => { daysComboRef = el }}>
            <input
              type="text"
              inputmode="numeric"
              class="tracking-days-input"
              aria-labelledby="tracking-days-lbl"
              value={String(days())}
              onChange={(event) => {
                const typed = Number(event.currentTarget.value.trim())
                if (Number.isFinite(typed) && typed >= 1) {
                  applyDays(typed)
                }
                // Re-sync to the effective (clamped) value, reverting invalid input.
                event.currentTarget.value = String(days())
              }}
              onKeyDown={(event) => {
                if (event.key === 'ArrowDown') { event.preventDefault(); setDaysMenuOpen(true) }
                else if (event.key === 'Escape') { setDaysMenuOpen(false) }
              }}
            />
            <button
              type="button"
              class="days-combo-toggle"
              tabindex="-1"
              aria-label={m.tracking_days_presets()}
              aria-expanded={daysMenuOpen()}
              aria-controls="tracking-days-menu"
              onClick={() => setDaysMenuOpen((open) => !open)}
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
            </button>
            <Show when={daysMenuOpen()}>
              <ul class="days-combo-menu" id="tracking-days-menu" aria-label={m.tracking_days_label()}>
                <For each={DAYS_OPTIONS}>
                  {(option) => (
                    <li>
                      <button
                        type="button"
                        class="days-combo-option"
                        classList={{ 'is-active': days() === option }}
                        aria-current={days() === option ? 'true' : undefined}
                        onClick={() => { applyDays(option); setDaysMenuOpen(false) }}
                      >
                        {option === 1 ? m.tracking_days_option_one() : m.tracking_days_option({ count: String(option) })}
                      </button>
                    </li>
                  )}
                </For>
              </ul>
            </Show>
          </div>
          <span class="tracking-days-unit">{m.tracking_days_unit()}</span>
        </div>

        {/* Inline-edit + keyboard discoverability hint — last in the tool line, so
            the only otherwise-on-screen cue (a hover text-cursor on editable cells)
            gets a written explanation without a separate band above the grid. */}
        <p class="tracking-hint">{m.tracking_edit_hint()}</p>
      </div>

      {/* A session-expiry refetch errors too, but the overlay owns that — keep the
          last-good grid (and the user's drafts) visible+dimmed behind it, not a
          jarring "load error". A genuine error (session OK) still shows the fallback. */}
      <Show when={!entries.isError || sessionExpired()} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table tracking-table"
            classList={{ 'is-fetching': entries.isFetching }}
            // A refetch (refresh / range change) keeps the previous rows visible
            // (keepPreviousData) — aria-busy + a subtle dim are the only in-flight
            // cue a sighted user gets, since the first-load spinner won't fire.
            aria-busy={entries.isFetching ? 'true' : undefined}
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
                                <Show
                                  when={fieldType === 'select' || fieldType === 'multiselect'}
                                  fallback={
                                    <>
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
                                    </>
                                  }
                                >
                                  {/* Ghost holds the column width so the overlaying single-select
                                      editor can't re-flow the table (multi-select wraps in flow). */}
                                  <Show when={fieldType === 'select'}>
                                    <span class="inline-ghost" aria-hidden="true">{cellContent(entry, col.key)}</span>
                                  </Show>
                                  <ChipSelect
                                    field={FIELD_BY_KEY.get(col.key)!}
                                    label={col.label()}
                                    initial={editor.draftValue(id, col.key) ?? (fieldType === 'multiselect' ? [] : '')}
                                    options={optionLookup}
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
                      {/* data-row-id (no data-col-key → not inline-editable) keeps focus
                          "inside the row" so clicking Delete isn't read as a row-leave. */}
                      <td class="tracking-row-actions" data-row-id={String(id)}>
                        <div class="row-actions">
                          {/* Per-row Continue / Prolong / Info — only for saved entries. */}
                          <Show when={id > 0}>
                            <RowAction label={m.tracking_continue()} keyshortcut={rowShortcut('Alt+C')} onClick={() => continueEntry(entry)}><ContinueIcon /></RowAction>
                            {/* Prolong rewrites the row's end to now — only meaningful on the
                                LATEST entry; on an older row it would silently overwrite a past
                                end with the current time, so it's hidden there. */}
                            <Show when={isLatestEntry(entry)}>
                              <RowAction label={m.tracking_prolong()} keyshortcut={rowShortcut('Alt+P')} onClick={() => void prolongLast(entry)}><ProlongIcon /></RowAction>
                            </Show>
                            <RowAction label={m.tracking_info()} keyshortcut={rowShortcut('Alt+I')} onClick={() => void showInfo(entry)}><InfoIcon /></RowAction>
                          </Show>
                          <RowAction label={m.admin_delete()} danger onClick={() => setPendingDelete(entry)}><TrashIcon /></RowAction>
                          {/* Force-save and discard (reset) share the unsaved cue: both show while the
                              row has pending edits, and always for a brand-new row (id <= 0). Each keeps
                              a reserved slot (visibility only toggles) so the Delete icon never shifts.
                              The disk force-saves (surfacing the full error); the reset throws the edits
                              away — restoring the DB values, or removing an unsaved new row. */}
                          <button type="button" class="link-button is-icon is-unsaved" classList={{ 'action-slot-hidden': !isUnsaved(id) }} aria-label={m.app_save()} title={m.app_save()} onClick={() => void editor.flushRow(id)}>
                            <DiskIcon />
                          </button>
                          <button type="button" class="link-button is-icon is-reset" classList={{ 'action-slot-hidden': !isUnsaved(id) }} disabled={editor.savingRows[id]} aria-label={m.tracking_reset()} title={m.tracking_reset()} onClick={() => resetEntry(entry)}>
                            <ResetIcon />
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
            {/* Range-aware: an empty N-day window is not "you have no entries ever",
                so name the window and offer to widen it (rather than only "add"). */}
            <p>{m.tracking_empty_range({ count: String(days()) })}</p>
            <div class="tracking-empty-actions">
              <button type="button" class="primary-button" onClick={() => addEntry()}>{m.tracking_empty_cta()}</button>
              <Show when={days() < WIDEN_DAYS}>
                <button type="button" class="action-button" onClick={() => applyDays(WIDEN_DAYS)}>{m.tracking_empty_widen({ count: String(WIDEN_DAYS) })}</button>
              </Show>
            </div>
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
          {/* Row-action icon key — the icons in the Actions column are also discoverable
              by hover/keyboard, but listing them here aids at-a-glance recognition. */}
          <p class="tracking-legend tracking-legend-icons">
            <span class="visually-hidden">{m.tracking_legend_icons()}: </span>
            <span class="tracking-legend-icon"><ContinueIcon /> {m.tracking_continue()}</span>
            <span class="tracking-legend-icon"><ProlongIcon /> {m.tracking_prolong()}</span>
            <span class="tracking-legend-icon"><InfoIcon /> {m.tracking_info()}</span>
            <span class="tracking-legend-icon"><TrashIcon /> {m.admin_delete()}</span>
            <span class="tracking-legend-icon"><DiskIcon /> {m.app_save()}</span>
            <span class="tracking-legend-icon"><ResetIcon /> {m.tracking_reset()}</span>
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
        <BulkEntryForm onSaved={() => void refreshWorklog()} />
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
