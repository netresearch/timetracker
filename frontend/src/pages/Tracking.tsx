import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createEffect, createMemo, createSignal, For, on, onCleanup, onMount, Show, type JSX } from 'solid-js'
import { Portal } from 'solid-js/web'

import { apiErrorMessage, getJson, postForm, postJson, ValidationError } from '../api/client'
import { activitiesQuery, ENTRIES_KEY, trackingCustomersQuery, trackingEntriesQuery, trackingProjectsQuery, trackingTicketSystemsQuery, upsertSavedEntry, type EntrySummaryResponse, type NamedOption, type SavedEntryResult, type SummaryScope, type TrackingEntry } from '../api/queries'
import { appConfig, canBulkEnter } from '../config'
import type { FieldDef, OptionLookup, OptionSource } from '../admin/types'
import { num, str } from '../lib/coerce'
import { dateFormat, formatUserDate } from '../lib/dateFormat'
import { formatMinutes, isoDate } from '../lib/format'
import { gridNav, type GridMoveHandle } from '../lib/gridNavigation'
import { chipValues, createInlineGridEdit, fieldSelectOptions, InlineEditor, INLINE_OVERLAY_TYPES, INLINE_TYPES } from '../lib/inlineGridEdit'
import { ChipSelect } from '../lib/chipSelect'
import { registerCommands } from '../lib/commandPalette'
import { getTrackingDays, setTrackingDays } from '../lib/trackingDaysPref'
import { getWorklogView, setWorklogView, type WorklogView } from '../lib/worklogViewPref'
import { getWorklogSort, setWorklogSort, WORKLOG_SORTS, type WorklogSort } from '../lib/worklogSortPref'
import WorklogViewSwitch from '../components/WorklogViewSwitch'
import WorklogTimeline from '../components/WorklogTimeline'
import { CalendarIcon, ContinueIcon, DiskIcon, DownloadIcon, InfoIcon, KebabIcon, PlusIcon, ProlongIcon, ResetIcon, ToolsIcon, TrashIcon } from '../lib/icons'
import { BulkEntryForm } from '../components/BulkEntryForm'
import { EntrySourceBadge } from '../components/EntrySourceBadge'
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

type RowCue = '' | 'is-daybreak' | 'is-pause' | 'is-overlap'

// Day-break / pause / overlap are a pure function of the displayed entries'
// (day, start, end) ordering, so derive them on the client from the rows we
// actually render. This is correct for ANY data — seed, import, future-dated —
// unlike the persisted `class` column, which is only (re)computed server-side
// when an entry is saved and is therefore stale/absent otherwise.
// Mirrors DayClassService::recalculate: a day's earliest entry is the day
// break; a later one is a pause (gap after the previous) or an overlap (starts
// before the previous ended), else plain. Like the backend it reads the HUMAN
// day shape only (ADR-025 §6): agent entries overlap by design, so they get no
// cue and never shift the day break or the previous-end baseline.
// Normalise a worklog date to an ISO day (YYYY-MM-DD), accepting both the
// grid's dd/mm/YYYY format and an already-ISO value (imported/seed rows).
function toIsoDay(dateValue: string | null): string | null {
  const value = str(dateValue)
  const iso = dmyToIso(value)
  if (iso !== null) {
    return iso
  }

  return /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : null
}

function deriveRowCues(entries: TrackingEntry[]): Map<number, RowCue> {
  // Parse each row's id/day/start/end ONCE, then sort by the precomputed key —
  // so parsing doesn't re-run inside the O(n log n) comparator.
  const rows = entries
    .filter((entry) => num(entry.id) > 0 && str(entry.date) !== '' && entry.source !== 'agent')
    .map((entry) => {
      const day = toIsoDay(entry.date) ?? str(entry.date)
      const start = str(entry.start)

      return { id: num(entry.id), day, start, end: str(entry.end), key: `${day} ${start}` }
    })
    .sort((a, b) => (a.key !== b.key ? (a.key < b.key ? -1 : 1) : a.id - b.id))

  const cues = new Map<number, RowCue>()
  let previousDay = ''
  let previousEnd = ''
  for (const row of rows) {
    let cue: RowCue = ''
    if (row.day !== previousDay) {
      cue = 'is-daybreak'
    } else if (row.start !== '' && previousEnd !== '') {
      // Only a fully-timed pair yields pause/overlap — mirrors the backend,
      // which skips the comparison when a start/end is missing (otherwise a
      // blank previous end would mark every later same-day row a pause).
      if (row.start > previousEnd) {
        cue = 'is-pause'
      } else if (row.start < previousEnd) {
        cue = 'is-overlap'
      }
    }
    cues.set(row.id, cue)
    previousDay = row.day
    previousEnd = row.end
  }

  return cues
}

// An entry sits in the future when its calendar day is after the local today
// (same client-clock convention as Month.tsx — no server-timezone skew).
function isFutureDay(dateValue: string | null, todayIso: string): boolean {
  const day = toIsoDay(dateValue)

  return day !== null && day > todayIso
}

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

// End prefill for a fresh row (#588, v4 parity): when the target day is today
// and the wall clock is already past the start, suggest max(now, start + the
// minimum) — the "previous end → now" span a user expects when booking right
// after finishing the work. That span deliberately covers unbooked gaps (e.g.
// lunch); both times are visibly prefilled and editable. Otherwise (start not
// in the past) keep the fixed start + minimum, blank when the minimum is 0 —
// the pre-#588 behaviour. Zero-padded H:i strings compare chronologically.
function suggestedEnd(start: string, isToday: boolean, minMinutes: number): string {
  const minEnd = minMinutes > 0 ? addMinutes(start, minMinutes) : ''
  if (!isToday) {
    return minEnd
  }
  const now = nowHi()
  if (now <= start) {
    return minEnd
  }

  return minEnd > now ? minEnd : now
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
  { name: 'date', label: () => m.tracking_col_date(), type: 'date', required: true, enhancedDate: true },
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

// Day totals are summed from durationMinutes and rendered in the same H:MM
// shape the server already sends per row, so a group header and its rows read
// as one unit rather than two notations.
function formatDuration(minutes: number): string {
  const total = Math.max(0, Math.round(minutes))

  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}

// Duration bars are scaled against a fixed working day, not against the longest
// row on screen: a per-screen scale would silently re-draw every bar when the
// range changes, so two days could never be compared. Anything longer simply
// fills the bar (capped), which the number beside it still states exactly.
// The design canvas's scale, verbatim: 150 px per 90 minutes, floored at 3 px so
// a six-minute entry still draws something (Main.dc.html, `px()`). The bar sits
// in a fixed 150 px track, so anything past 90 minutes fills it and the exact
// figure beside it carries the rest — the canvas accepts that, and a bar that
// grew without limit would push the figure out of the cell.
const DURATION_BAR_PX_PER_MINUTE = 150 / 90

// Columns whose value is context rather than the entry itself: inside a day
// section a repeat of these says nothing new, so it is shown once (see
// renderRow). Start/end/ticket/description/duration always differ per entry and
// are never suppressed.
// The grouped view's own columns, from the design canvas: the context (customer,
// project, activity) is ONE block cell shown once per block, start and end are
// one "12:41–13:17" cell, and the duration cell carries the bars. Widths follow
// the canvas: 208px block, 112px time, 1fr description, 236px duration.
const GROUPED_COLUMNS: { key: string; label: () => string; numeric?: boolean }[] = [
  { key: 'context', label: () => m.worklog_col_context() },
  { key: 'time', label: () => m.worklog_col_time(), numeric: true },
  { key: 'description', label: () => m.tracking_col_description() },
  { key: 'duration', label: () => m.tracking_col_duration(), numeric: true },
]

// Two entries belong to the same block when customer, project and activity all
// match — the block is the unit whose context is stated once.
// What Enter (or a double-click on the cell rather than one of its parts) edits
// in a composite cell. The parts themselves are addressed directly; this is the
// keyboard's way in, so a composite cell is never a dead end.
const COMPOSITE_PRIMARY_FIELD: Record<string, string> = {
  context: 'project',
  time: 'start',
}

/** Every field a composite cell holds — the cell counts as "editing" while any
 *  of them is, so gridNav keeps its hands off the roving tabindex. */
const COMPOSITE_PARTS: Record<string, string[]> = {
  context: ['project', 'customer', 'activity'],
  time: ['start', 'end'],
  description: ['description', 'ticket'],
}

// Two entries share a block when the dimension the block column shows is the
// same. Inside a day card that is customer/project/activity; inside a customer
// card those are already the card's own name, so the block is the day.
function blockKey(entry: TrackingEntry, byContext: boolean): string {
  return byContext
    ? (entry.date ?? '')
    : `${entry.customer ?? ''}/${entry.project ?? ''}/${entry.activity ?? ''}`
}

// Non-colour cue for the derived row cue (WCAG 1.4.1 / 1.3.1).
function cueLabel(cue: RowCue): string {
  switch (cue) {
    case 'is-daybreak':
      return m.tracking_class_daybreak()
    case 'is-pause':
      return m.tracking_class_pause()
    case 'is-overlap':
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

// Construct compact date-only values in UTC (the same pattern as dateFormat.ts
// formatAuto) so a DST transition or timezone mismatch can never shift a value
// onto the neighbouring calendar day.
function dateFromIso(iso: string): Date {
  const [year, month, day] = iso.split('-').map(Number)

  return new Date(Date.UTC(year!, month! - 1, day!))
}

let compactFormatterLocale: string | null = null
let compactFormatter: Intl.DateTimeFormat | undefined

// Day+month in the active locale's order, always zero-padded to two digits
// (e.g. `05.01.` / `15.01.`, not `5.1.` / `15.1.`) — a ragged mix of one- and
// two-digit fields is hard to scan down a column.
function compactDateByLocale(iso: string): string {
  const locale = appConfig().locale
  if (compactFormatterLocale !== locale) {
    compactFormatterLocale = locale
    compactFormatter = undefined
  }
  compactFormatter ??= new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', timeZone: 'UTC' })

  return compactFormatter.format(dateFromIso(iso))
}

// Same two-digit day+month, but following a user's custom pattern for the field
// order and separator instead of the locale default.
function customCompactDate(iso: string): string {
  const pref = dateFormat()
  const pattern = pref.mode === 'custom' ? pref.pattern : ''
  const firstDay = pattern.search(/D|%d|%D/)
  const firstMonth = pattern.search(/M|%m/)
  const [, month, day] = iso.split('-')
  const dayFirst = firstDay >= 0 && (firstMonth < 0 || firstDay < firstMonth)
  const separator = pattern.includes('/') ? '/' : pattern.includes('.') ? '.' : pattern.includes(' ') ? ' ' : '-'
  const trailing = separator === '.' ? separator : ''

  return dayFirst
    ? `${day!}${separator}${month!}${trailing}`
    : `${month!}${separator}${day!}${trailing}`
}

// Two widths of the same date so the responsive table can shrink the Date
// column purely in CSS: full (the user's preferred format) and a compact
// two-digit day+month that still identifies a calendar date — never
// weekday-only or a bare day-of-month, both ambiguous beyond a very small
// visible range (issue #520).
function dateParts(value: string): { full: string; compact: string } {
  const full = displayDate(value)
  const iso = dmyToIso(value) ?? value
  const match = /^(\d{4}-\d{2}-\d{2})/.exec(iso)
  const isoDate = match?.[1]
  if (isoDate !== undefined) {
    const compact = dateFormat().mode === 'custom' ? customCompactDate(isoDate) : compactDateByLocale(isoDate)

    return { full, compact }
  }

  return { full, compact: full }
}

function ColumnHeader(props: { label: string; icon?: JSX.Element }): JSX.Element {
  return (
    <>
      <span class="th-label">{props.label}</span>
      <Show when={props.icon !== undefined}>
        <span class="th-icon" aria-hidden="true">{props.icon}</span>
      </Show>
    </>
  )
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
 * The SolidJS work-log grid (/ui/tracking).
 * Inline cell editing reuses the shared inline-grid controller; start/end
 * accept terse times (930, 9:30a) parsed to H:i; saving a row POSTs the whole
 * entry to /tracking/save and refetches (the server recomputes duration + the
 * row class).
 */
export default function Tracking() {
  const queryClient = useQueryClient()
  // The day range persists across remounts/logins (client-side, like the theme).
  const [days, setDays] = createSignal<number>(Math.min(getTrackingDays(DEFAULT_DAYS), MAX_DAYS))
  // The chosen view persists the same way the day range does (client-side).
  // Which nav layout is live. navLayoutPref applies it to <html> and fires
  // tt:layout-change when the Settings page switches it, so the toolbar follows
  // without a reload.
  const isSide = (): boolean => document.documentElement.getAttribute('data-nav-layout') === 'side'
  const [navSideLayout, setNavSideLayout] = createSignal(isSide())
  const [toolsSlot, setToolsSlot] = createSignal<HTMLElement | null>(null)
  onMount(() => {
    setToolsSlot(document.getElementById('sidebar-tools-slot'))
    const onLayoutChange = (): void => { setNavSideLayout(isSide()) }
    window.addEventListener('tt:layout-change', onLayoutChange)
    onCleanup(() => window.removeEventListener('tt:layout-change', onLayoutChange))
  })

  const [sort, setSortSignal] = createSignal<WorklogSort>(getWorklogSort())
  const chooseSort = (next: WorklogSort): void => {
    setSortSignal(next)
    setWorklogSort(next)
  }

  const [view, setViewSignal] = createSignal<WorklogView>(getWorklogView())
  const chooseView = (next: WorklogView): void => {
    setViewSignal(next)
    setWorklogView(next)
  }
  // Timeline is read-only by design, so activating an entry there hands it to the
  // grid: switch to the flat view (every column present, nothing suppressed) and
  // put the caret in that row, so the jump lands somewhere you can actually edit.
  const jumpToEntry = (entryId: number): void => {
    chooseView('flat')
    queueMicrotask(() => {
      const cell = document.querySelector<HTMLElement>(`td[data-row-id="${entryId}"][data-col-key="description"]`)
      cell?.scrollIntoView({ block: 'center' })
      cell?.focus()
    })
  }
  // Preset-range combobox: the menu always lists every preset (unlike a native
  // datalist, which filters by the typed value and forced the user to clear the
  // field to pick another range). Free typing still applies a custom day count.
  const [daysMenuOpen, setDaysMenuOpen] = createSignal(false)
  // Active option for keyboard navigation (aria-activedescendant), -1 = none.
  const [daysActiveIdx, setDaysActiveIdx] = createSignal(-1)
  let daysComboRef: HTMLDivElement | undefined
  const openDaysMenu = (): void => {
    const current = DAYS_OPTIONS.indexOf(days() as (typeof DAYS_OPTIONS)[number])
    setDaysActiveIdx(Math.max(0, current))
    setDaysMenuOpen(true)
  }
  const closeDaysMenu = (): void => { setDaysMenuOpen(false); setDaysActiveIdx(-1) }
  const chooseDays = (value: number): void => { applyDays(value); closeDaysMenu() }
  // The collapsed row-actions menu (kebab) — one open at a time, keyed by row id.
  // Opens on hover AND on click/tap (touch + keyboard have no hover; WCAG 1.4.13:
  // the popup is hoverable — it lives inside the hovered wrapper — dismissible
  // via Escape/click-outside, and persistent until dismissed).
  const [actionsMenuRow, setActionsMenuRow] = createSignal<number | null>(null)
  // Closing on pointerleave is deferred by a short grace period: a slow or
  // diagonal move from the kebab to its popup may clip past the hoverable area
  // for a moment, and an immediate close makes the menu vanish mid-transit.
  // Re-entering (or opening another row's menu) cancels the pending close.
  let actionsMenuCloseTimer: ReturnType<typeof setTimeout> | undefined
  const cancelActionsMenuClose = (): void => { clearTimeout(actionsMenuCloseTimer) }
  const closeActionsMenu = (): void => { cancelActionsMenuClose(); setActionsMenuRow(null) }
  const scheduleActionsMenuClose = (id: number): void => {
    cancelActionsMenuClose()
    actionsMenuCloseTimer = setTimeout(() => { if (actionsMenuRow() === id) { setActionsMenuRow(null) } }, 150)
  }
  onCleanup(cancelActionsMenuClose)
  // The popup is position:fixed (to escape the table's overflow clipping), so its
  // viewport coordinates are set here from the kebab's rect when it mounts. The
  // kebab button is the popup's sibling inside .action-menu. Right-aligned to the
  // button and opened below it, flipping above when it would overflow the viewport
  // bottom; clamped horizontally so it never leaves the viewport.
  const positionActionsMenu = (popup: HTMLElement): void => {
    // Hide until positioned: the ref fires before layout, so without this the popup
    // paints one frame at its unpositioned (static) coordinates — a visible flicker —
    // before the next-frame clamp runs. A visibility:hidden box still has layout, so
    // the getBoundingClientRect() below stays accurate.
    popup.style.visibility = 'hidden'
    // Measure on the NEXT frame: at ref-callback time the popup isn't laid out yet,
    // so popup.getBoundingClientRect() reports width/height 0 — the clamp below would
    // then leave left at anchor.right and the menu would open off the right edge of
    // the viewport. rAF gives it a real box before we position it.
    requestAnimationFrame(() => {
      // The kebab is the .action-menu's own trigger (aria-haspopup=menu) — target it
      // explicitly, not the first descendant button, which would also match the
      // menuitem buttons inside this very popup.
      const button = popup.parentElement?.querySelector('[aria-haspopup="menu"]')
      if (!button || !popup.isConnected) {
        return
      }
      const anchor = button.getBoundingClientRect()
      const menu = popup.getBoundingClientRect()
      const gap = 2
      const left = Math.max(4, Math.min(anchor.right - menu.width, window.innerWidth - menu.width - 4))
      const below = anchor.bottom + gap
      const flipUp = below + menu.height > window.innerHeight && anchor.top - menu.height - gap >= 0
      popup.style.left = `${left}px`
      popup.style.top = `${flipUp ? anchor.top - menu.height - gap : below}px`
      popup.style.visibility = 'visible'
    })
  }
  // The fixed popup would strand from its button on scroll/resize; attach a dismiss
  // handler only while a menu is open, rather than a permanent global listener.
  createEffect(() => {
    if (actionsMenuRow() === null) {
      return
    }
    const dismiss = (): void => closeActionsMenu()
    // Capture phase so a scroll on the inner .table-scroll container is caught too.
    window.addEventListener('scroll', dismiss, { capture: true, passive: true })
    window.addEventListener('resize', dismiss, { passive: true })
    onCleanup(() => {
      window.removeEventListener('scroll', dismiss, { capture: true })
      window.removeEventListener('resize', dismiss)
    })
  })
  onMount(() => {
    const onDocPointer = (event: PointerEvent): void => {
      if (daysComboRef !== undefined && !daysComboRef.contains(event.target as Node)) {
        closeDaysMenu()
      }
      // event.target can be a non-Element node (e.g. a text node) — guard
      // before calling closest() so the global handler can't throw.
      const target = event.target
      if (actionsMenuRow() !== null && (!(target instanceof Element) || target.closest('.action-menu') === null)) {
        closeActionsMenu()
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
  // The scroll container — the responsive controller measures overflow against
  // it. A signal (not a plain ref) so the observer effect below re-binds when
  // the <Show>-wrapped table unmounts and remounts (e.g. after a load error
  // clears), rather than staying bound to a detached element.
  const [scrollEl, setScrollEl] = createSignal<HTMLDivElement>()
  // The grid's move handle — used to restore cell focus after a row is deleted.
  let gridHandle: GridMoveHandle | null = null
  const rows = createMemo<TrackingEntry[]>(() => [...newRows(), ...(entries.data ?? [])])

  // Day sections, keyed by the day STRING rather than by a freshly built group
  // object: <For> keys by reference, so a new object per recompute would rebuild
  // every <tbody> — and with it every row — on any change, which tears focus out
  // of an open inline editor. The day keys are stable by value and the entry
  // objects inside are the same references, so unchanged rows are reused.
  // Befund 5 / the design canvas: a human entry and the agent walltime it was
  // logged with are one unit, and since #693 the link is a real field
  // (pairedEntry). In the grouped view the pair renders as ONE row carrying two
  // bars; an agent entry whose partner is outside the range still stands alone,
  // so nothing is ever hidden.
  const entryById = createMemo<Map<number, TrackingEntry>>(() => {
    const map = new Map<number, TrackingEntry>()
    for (const entry of rows()) {
      map.set(num(entry.id), entry)
    }

    return map
  })
  const pairedAgentOf = (entry: TrackingEntry): TrackingEntry | undefined => {
    if (entry.source === 'agent' || entry.pairedEntry === null || entry.pairedEntry === undefined) {
      return undefined
    }

    const partner = entryById().get(entry.pairedEntry)

    return partner?.source === 'agent' ? partner : undefined
  }
  const visibleRows = createMemo<TrackingEntry[]>(() => {
    if (view() !== 'grouped') {
      return rows()
    }

    const foldedAway = new Set<number>()
    for (const entry of rows()) {
      const agent = pairedAgentOf(entry)
      if (agent !== undefined) {
        foldedAway.add(num(agent.id))
      }
    }

    return rows().filter((entry) => !foldedAway.has(num(entry.id)))
  })

  // What a card is, and what the block column inside it shows, follow the chosen
  // order: by time the card is a DAY and the block is the context; by context the
  // card is a CUSTOMER AND PROJECT and the block is the day. Either way the card
  // header names the thing all its rows share, and the block column names what
  // varies one level down — so the grouping always says something true rather
  // than "these happened to be adjacent".
  const groupKeyOf = (entry: TrackingEntry): string =>
    sort() === 'context'
      ? `${relationLabel(entry, 'customer')} · ${relationLabel(entry, 'project')}`
      : (entry.date ?? '')

  const groupKeys = createMemo<string[]>(() => {
    const keys: string[] = []
    for (const entry of visibleRows()) {
      const key = groupKeyOf(entry)
      if (!keys.includes(key)) {
        keys.push(key)
      }
    }

    // By time the server's order (newest first) already carries the meaning; by
    // context the cards are named things, so they read alphabetically.
    return sort() === 'context' ? [...keys].sort((a, b) => a.localeCompare(b)) : keys
  })

  const entriesByGroup = createMemo<Map<string, TrackingEntry[]>>(() => {
    const map = new Map<string, TrackingEntry[]>()
    for (const entry of visibleRows()) {
      const key = groupKeyOf(entry)
      const list = map.get(key)
      if (list === undefined) {
        map.set(key, [entry])
        continue
      }

      list.push(entry)
    }

    return map
  })

  // The card's own heading. A day card shows the date; a customer card shows
  // what it is called.
  const groupLabel = (key: string): string => (sort() === 'context' ? key : displayDate(key))

  const groupFacts = (key: string): { human: number; agent: number; estimated: number; humanRows: number } => {
    let human = 0
    let agent = 0
    let estimated = 0
    let humanRows = 0
    for (const entry of entriesByGroup().get(key) ?? []) {
      const partner = pairedAgentOf(entry)
      if (partner !== undefined) {
        agent += partner.durationMinutes
      }

      if (entry.source === 'agent') {
        agent += entry.durationMinutes
        continue
      }

      human += entry.durationMinutes
      humanRows += 1
      if (entry.estimated) {
        estimated += 1
      }
    }

    return { human, agent, estimated, humanRows }
  }

  // Day-break/pause/overlap cues, derived from the rendered rows (see
  // deriveRowCues) instead of the persisted `class` — so they are correct for
  // future-dated and never-saved-through-the-app entries too.
  // Day break, pause and overlap are statements about ADJACENCY IN TIME: "this
  // row starts after the previous one ended". Ordered by customer and project the
  // neighbouring row is no longer the neighbouring minute, so the cues would
  // decorate pairs that never met — worse than absent, because a red edge still
  // reads as a warning. They are withheld for that order; the time order, where
  // they mean what they say, keeps them.
  const timeOrdered = (): boolean => !(view() === 'grouped' && sort() === 'context')
  const rowCues = createMemo<Map<number, RowCue>>(() => (timeOrdered() ? deriveRowCues(rows()) : new Map()))
  // Local today (client clock, per Month.tsx convention) — future entries are
  // days strictly after it. Only relevant when the user opted into show-future.
  const todayIso = isoDate(new Date())
  const rowIsFuture = (entry: TrackingEntry): boolean =>
    appConfig().showFuture && num(entry.id) > 0 && isFutureDay(entry.date, todayIso)
  // Rows render newest-first, so any future block sits at the top. The "Zukunft"
  // divider renders directly above the first non-future (today/past) entry that
  // follows the future block; null when nothing (or everything) is future.
  const futureDividerBeforeId = createMemo<number | null>(() => {
    if (!appConfig().showFuture) {
      return null
    }
    let sawFuture = false
    for (const entry of rows()) {
      if (num(entry.id) <= 0) {
        continue
      }
      const future = isFutureDay(entry.date, todayIso)
      if (sawFuture && !future) {
        return num(entry.id)
      }
      sawFuture = sawFuture || future
    }

    return null
  })

  // Progressive responsive thinning: raise .is-thin-N on the table (each level
  // tightens padding / collapses the actions / shortens the date / truncates a
  // column / hides a low-value column, in priority order — see app.css) until
  // the table no longer overflows its scroll container. Cells are nowrap, so
  // this fires BEFORE anything wraps. Re-run on container resize AND on
  // row/content changes. A direct measured scan is intentionally used here:
  // auto table layout plus display changes are not guaranteed to be perfectly
  // monotonic at every rung, and 15 probes is cheap enough for correctness.
  const MAX_THIN = 8
  function applyThinLevel(table: HTMLElement, level: number): void {
    for (let i = 1; i <= MAX_THIN; i++) {
      table.classList.toggle('is-thin-' + i, i <= level)
    }
    table.dataset.fitLevel = String(level)
  }
  function fitTrackingTable(): void {
    const scroll = scrollEl()
    const table = tableEl
    if (scroll === undefined || table === undefined) {
      return
    }
    // clientWidth is stable across the search (thinning changes only the table's
    // own width), so read it once — the per-probe reflow is driven by scrollWidth.
    const clientWidth = scroll.clientWidth
    for (let candidate = 0; candidate <= MAX_THIN; candidate += 1) {
      applyThinLevel(table, candidate)
      if (scroll.scrollWidth <= clientWidth + 1) {
        break
      }
    }
  }
  // Manage the ResizeObserver reactively: it (re)binds whenever the scroll
  // element becomes available and disconnects when it goes away or remounts.
  createEffect(() => {
    const scroll = scrollEl()
    if (scroll === undefined) {
      return
    }
    fitTrackingTable()
    const observer = new ResizeObserver(() => fitTrackingTable())
    observer.observe(scroll)
    onCleanup(() => observer.disconnect())
  })
  // Row/content changes alter the table's natural width without resizing the
  // container, so re-fit after they render (rAF defers past the DOM update).
  // Chip labels resolve asynchronously from the option queries — reading them
  // here re-fits once the IDs become (wider) names, so the table doesn't stay
  // stuck at a level computed before the labels loaded. Cancelling the frame on
  // cleanup collapses rapid successive updates into one fit and prevents a
  // post-unmount run.
  createEffect(
    on(
      [rows, () => customers.data, () => projects.data, () => activities.data],
      () => {
        const rafId = requestAnimationFrame(fitTrackingTable)
        onCleanup(() => cancelAnimationFrame(rafId))
      },
    ),
  )
  // The External-ticket column is read-only and often empty; when NO loaded row
  // has a value it is dropped from the rendered grid entirely (data-driven — it
  // reappears as soon as a fetched row carries one). Removing the cells from the
  // DOM (rather than display:none) keeps gridNav's live cell indices and
  // aria-colcount consistent: a hidden-but-present cell would still be an
  // arrow-key stop.
  const hasExtTicket = createMemo<boolean>(() => rows().some((row) => str(row.extTicket) !== ''))
  const visibleColumns = createMemo(() => {
    // The grouped view has its own column model (see GROUPED_COLUMNS): a block
    // cell for the context, one time cell, and the duration cell carrying the
    // bars. The date lives in the day heading, and a ticket appears under its
    // description rather than in a column that was empty in every row.
    if (view() === 'grouped') {
      // The block column names what it actually shows, which the order decides —
      // a header reading "Kunde · Projekt" over a column of dates is just wrong.
      return GROUPED_COLUMNS.map((col) => (col.key === 'context'
        ? { ...col, label: (): string => (sort() === 'context' ? m.worklog_col_day_activity() : m.worklog_col_context()) }
        : col))
    }

    return hasExtTicket() ? COLUMNS : COLUMNS.filter((col) => col.key !== 'extTicket')
  })
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

  // The person's own timeline (ADR-025): agent entries run in parallel by design,
  // so "the last entry" for Add/Continue/Prolong is always the last HUMAN one —
  // an agent twin sorting first must neither seed the next start nor be prolonged.
  const humanEntries = (): TrackingEntry[] => (entries.data ?? []).filter((entry) => entry.source !== 'agent')

  // Suggested start for a fresh row / empty start cell: continue from the end of
  // the chronologically last entry OF THE TARGET DAY — the day the row is added
  // to, which is always today for Add/Continue (#588). Entries are sorted
  // newest-first by date+start, so the first entry dated today is that day's
  // last one; a future-dated entry (show-future) sorting above it must not
  // shadow it. The FIRST entry of a day keeps the previous behaviour and starts
  // at the current wall-clock time, not an older day's last end.
  const suggestedStart = (): string => {
    const todayIso = dmyToIso(todayDmy())
    const lastOfDay = humanEntries().find((entry) => dmyToIso(str(entry.date)) === todayIso)

    return lastOfDay !== undefined ? (str(lastOfDay.end) || nowHi()) : nowHi()
  }

  // On commit: ticket → derive project/customer; customer change → clear a now-
  // mismatched project; start/end → normalize a terse time (1300 → 13:00) so the
  // cell shows the fixed value immediately, not on the next refetch.
  function handleCommit(id: number, colKey: string, value: unknown): void {
    if (colKey === 'ticket') {
      const ticketKey = str(value).toUpperCase().trim()
      if (ticketKey === '') {
        return
      }
      const prefix = ticketKey.split(/[-:]/)[0] ?? ''
      const candidates = projects.data ?? []
      // Exact subticket match wins over a prefix match: the synced subtickets list
      // enumerates specific keys (possibly from another Jira project), so it is more
      // precise than the jiraId prefix rule — the backend accepts it the same way
      // (SaveEntryAction::isKnownSubticket).
      const bySubticket = candidates.find(
        (candidate) => candidate.subtickets !== '' && candidate.subtickets.toUpperCase().split(/[\s,]+/).includes(ticketKey),
      )
      // Fallback: jiraId is a comma/space-separated list of allowed prefixes (the
      // backend splits it the same way in validateTicketPrefix), so match membership —
      // an exact === missed multi-prefix projects, so an external ticket like
      // DHLSUP-1 derived the wrong/no project and the save was rejected (#453).
      const byPrefix = prefix === ''
        ? undefined
        : candidates.find(
            (candidate) => candidate.jiraId !== '' && candidate.jiraId.toUpperCase().split(/[\s,]+/).includes(prefix),
          )
      const project = bySubticket ?? byPrefix
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
    // Add/Continue rows carry a temporary negative id until they're first saved.
    // A "Continue" row is pre-filled from another entry, so its draft equals its
    // seed — mark it new so a complete one still saves instead of being dropped
    // as a no-op (#495).
    isNewRow: (entry) => num(entry.id) <= 0,
    fieldFor: (colKey) => FIELD_BY_KEY.get(colKey),
    isInlineEditable: (colKey) => {
      const field = FIELD_BY_KEY.get(colKey)

      return field !== undefined && INLINE_TYPES.has(field.type)
    },
    seedDraft: (entry) => {
      // A fresh row prefills start with the suggested start (see suggestedStart),
      // then end via suggestedEnd (max(now, start + minimum) for a today row whose
      // start lies in the past; start + minimum otherwise — #588). Both respect
      // the suggest-time opt-out; an already-set value always wins.
      const start = str(entry.start) || (appConfig().suggestTime ? suggestedStart() : '')
      const isToday = toIsoDate(str(entry.date)) === dmyToIso(todayDmy())

      return {
        id: num(entry.id),
        date: toIsoDate(str(entry.date)),
        start,
        end: str(entry.end) || (start !== '' && appConfig().suggestTime ? suggestedEnd(start, isToday, appConfig().minEntryDuration) : ''),
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

      // Hand the controller the persisted id: a created row is re-keyed from its
      // temp id, and an Enter-triggered save then resumes editing on the new row.
      return saved.result.id
    },
    // An Enter-save that persists (re-keys) a new row continues the edit session
    // in the description column of the persisted row (#588).
    resumeColAfterCreate: 'description',
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

  // The ticket-system URL for a ticket, resolved via the entry's project
  // (with the bugs.nr fallback).
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
        : (
          <a
            class="ticket-link"
            href={ticketUrlFor(ticket, num(row.project))}
            target="_blank"
            rel="noopener noreferrer"
            title={m.tracking_ticket_link_hint()}
            onClick={(event) => {
              // A plain click starts inline editing (it bubbles to the cell);
              // following the link needs Ctrl/⌘ as the activation key. Middle
              // click, keyboard activation and the context menu are unaffected
              // (none of them dispatch a plain unmodified click).
              if (!event.ctrlKey && !event.metaKey) {
                event.preventDefault()
              }
            }}
          >{ticket}</a>
        )
    }
    if (colKey === 'date') {
      const parts = dateParts(str(editor.overlayRow(entry).date))

      return (
        <>
          {/* Two widths; the responsive table CSS shows exactly one per column width. */}
          <span class="dt dt-full">{parts.full}</span>
          <span class="dt dt-compact">{parts.compact}</span>
          <Show when={cueLabel(rowCues().get(num(entry.id)) ?? '') !== ''}>
            <span class="visually-hidden"> ({cueLabel(rowCues().get(num(entry.id)) ?? '')})</span>
          </Show>
        </>
      )
    }
    // Befund 3: in the WORKLOG a relation reads as plain text, not as a chip.
    // A chip is a bordered, filled object that says "this is one selectable
    // thing" — useful while editing, pure non-data ink when the same three
    // labels repeat down 24 rows. The admin grids keep their chips (see the
    // revised house rule in frontend/AGENTS.md); the editor still shows a
    // ChipSelect, so the affordance appears exactly when it means something.
    const field = FIELD_BY_KEY.get(colKey)
    if (field !== undefined && (field.type === 'select' || field.type === 'multiselect')) {
      const values = chipValues((editor.overlayRow(entry) as unknown as Record<string, unknown>)[colKey])
      const options = fieldSelectOptions(field, readOptionLookup)
      const labelOf = (value: string | number): string =>
        options.find((option) => String(option.value) === String(value))?.label ?? String(value)

      return <span class="relation-text">{values.map(labelOf).join(', ')}</span>
    }

    // A truncation box so the responsive thinning can ellipsis free-text columns
    // (Description) — a bare <td> in an auto-layout table can't do text-overflow.
    // ADR-025: the description cell also carries the source/estimated badge —
    // source is fixed on the row (not inline-editable), so read the base entry.
    if (colKey === 'description') {
      // Befund 6: in the grouped view the badge is dropped — agent time reads
      // from the hatched duration bar and "estimated" from the ≈ at the figure
      // plus the count in the day heading. The flat view keeps it, having
      // neither carrier.
      return (
        <span class="cell-desc-badged">
          <span class="cell-trunc">{displayCell(entry, colKey)}</span>
          <Show when={view() !== 'grouped'}>
            <EntrySourceBadge source={entry.source} estimated={entry.estimated} />
          </Show>
        </span>
      )
    }

    return <span class="cell-trunc">{displayCell(entry, colKey)}</span>
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

    return active !== undefined && num(active.id) > 0 ? active : humanEntries()[0]
  }

  // The most recent saved human entry (entries are returned newest-first) — the
  // only row whose end Prolong may rewrite to now without corrupting an older span.
  const isLatestEntry = (entry: TrackingEntry): boolean => {
    const latest = humanEntries()[0]

    return latest !== undefined && num(latest.id) === num(entry.id)
  }

  // Alt+I: per-customer/project/activity/ticket totals for the cursor row (or
  // the latest entry), from GET /api/v2/entries/{id}/summary (ADR-022; also
  // carries `estimate` and `warnings` beyond the four scopes shown here).
  async function showInfo(entry?: TrackingEntry): Promise<void> {
    const target = entry ?? activeOrLatestEntry()
    if (target === undefined) {
      return
    }
    setPageError('')
    try {
      const result = await getJson<EntrySummaryResponse>(`/api/v2/entries/${num(target.id)}/summary`)
      setSummary([result.customer, result.project, result.activity, result.ticket])
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
      // /tracking/delete reads form params ($request->request), so it must be
      // posted as a form — not a JSON body.
      await postForm('/tracking/delete', { id: num(entry.id) })
      // Drop any pending inline draft for the now-deleted entry — and for its
      // ADR-025 pair partner, which the server deletes together with it.
      editor.takeDraft(num(entry.id))
      if ((entry.pairedEntry ?? null) !== null) {
        editor.takeDraft(num(entry.pairedEntry))
      }
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
      // A web-UI row is always a human self-log (ADR-025 §4); never estimated.
      source: 'human',
      estimated: false,
      ...seed,
    }
    tempId -= 1
    setNewRows((list) => [row, ...list])
    // Reset the grid's roving cursor to the new row BEFORE opening the editor:
    // the editor's Tab/Shift+Tab move walks from the roving td[tabindex="0"], so
    // leaving it wherever it was (initially the header) made Shift+Tab land on
    // the "Datum" column heading instead of the previous cell (#588). Order
    // matters — beginEdit's editor grabs focus on mount, and a later td.focus()
    // would steal it back out of the input.
    gridHandle?.focusCell(num(row.id), firstCol)
    editor.beginEdit(num(row.id), firstCol)
  }

  // Add (Alt+A): a blank entry. suggestedStart continues from the end of today's
  // last entry; the first entry of a day starts at the current time.
  // Editing starts in the ticket column (#588): a ticket number is what most
  // users enter first, and it auto-derives customer/project via the prefix map.
  function addEntry(): void {
    pushNewRow({ start: appConfig().suggestTime ? suggestedStart() : '' }, 'ticket')
  }

  // Continue: clone the cursor row's (or, with no cursor, the latest entry's)
  // customer/project/activity/ticket/description into a fresh row; the times are
  // prefilled by seedDraft (suggest-time). Editing starts in the description
  // column (#588): everything else is inherited from the continued entry, so the
  // description is the one field a continued entry usually changes.
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
      'description',
    )
  }

  // Prolong-last (Alt+P): set the latest entry's end to now and save it.
  async function prolongLast(entry?: TrackingEntry): Promise<void> {
    const base = entry ?? humanEntries()[0]
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

  // The grouped view's cells, following the design canvas. The context block,
  // the composite time cell and the duration cell exist only here; description
  // keeps the shared renderer so inline editing works unchanged.
  // The label a relation shows. The block cell and the context ordering both read
  // it, so what the eye groups and what the sort groups can never disagree.
  const relationLabel = (entry: TrackingEntry, key: string): string => {
    const field = FIELD_BY_KEY.get(key)
    if (field === undefined) {
      return ''
    }

    const values = chipValues((editor.overlayRow(entry) as unknown as Record<string, unknown>)[key])
    const options = fieldSelectOptions(field, readOptionLookup)

    return values
      .map((value) => options.find((option) => String(option.value) === String(value))?.label ?? String(value))
      .join(', ')
  }
  const groupedCell = (entry: TrackingEntry, colKey: string, startsBlock: () => boolean): JSX.Element => {
    const row = editor.overlayRow(entry)
    const id = num(entry.id)

    // A composite cell (context, time) holds several real fields. It is still ONE
    // table cell — gridNav counts cells — but each part is its own edit target:
    // double-click or Enter on the part opens that field's editor in place. This
    // is what keeps the canvas layout without making the grouped view read-only.
    const part = (fieldKey: string, text: () => string, extraClass = ''): JSX.Element => {
      const field = FIELD_BY_KEY.get(fieldKey)
      const fieldType = field?.type
      const isChip = fieldType === 'select' || fieldType === 'multiselect'

      return (
        <Show
          when={editor.isEditing(id, fieldKey)}
          fallback={
            <span
              class={`worklog-part ${extraClass}`.trimEnd()}
              title={m.tracking_edit_hint_part({ field: field?.label() ?? fieldKey })}
              onDblClick={(event) => { event.stopPropagation(); editor.beginEdit(id, fieldKey) }}
            >{text()}</span>
          }
        >
          {/* The ghost holds the part's width while the editor overlays it, so
              opening one does not shove its neighbours sideways — the same
              device the flat grid's cells use. */}
          <span class={`worklog-part-edit ${extraClass}`.trimEnd()}>
            <span class="inline-ghost" aria-hidden="true">{text()}</span>
            <Show
              when={isChip}
              fallback={
                <InlineEditor
                  field={field!}
                  label={field?.label() ?? fieldKey}
                  initial={editor.draftValue(id, fieldKey) ?? ''}
                  seed={editor.seedChar()}
                  options={optionLookup}
                  onCommit={editor.commitCell}
                  onCancel={editor.cancelCell}
                />
              }
            >
              {/* A relation must be picked, not typed: the plain text editor
                  showed its raw id, which is what a user saw as "a number". */}
              <ChipSelect
                field={field!}
                label={field?.label() ?? fieldKey}
                initial={editor.draftValue(id, fieldKey) ?? (fieldType === 'multiselect' ? [] : '')}
                options={optionLookup}
                multiple={fieldType === 'multiselect'}
                onCommit={editor.commitCell}
                onCancel={editor.cancelCell}
              />
            </Show>
          </span>
        </Show>
      )
    }

    if (colKey === 'context') {
      // Continuation rows render nothing: the block already said it.
      if (!startsBlock()) {
        return ''
      }

      const label = (key: string): string => relationLabel(entry, key)

      // A customer card already names its customer and project, so its block
      // column states the day and the activity instead — what still varies there.
      if (sort() === 'context') {
        return (
          <span class="worklog-block">
            <span class="worklog-block-project num">{displayDate(str(row.date))}</span>
            <span class="worklog-block-meta">{part('activity', () => label('activity'))}</span>
          </span>
        )
      }

      return (
        <span class="worklog-block">
          {part('project', () => label('project'), 'worklog-block-project')}
          <span class="worklog-block-meta">
            {part('customer', () => label('customer'))} · {part('activity', () => label('activity'))}
          </span>
        </span>
      )
    }

    if (colKey === 'time') {
      const cue = rowCues().get(num(entry.id)) ?? ''

      return (
        <span class="worklog-time" classList={{ 'is-overlap': cue === 'is-overlap' }}>
          <span class="num">{part('start', () => str(row.start))}–{part('end', () => str(row.end))}</span>
          {/* Befund 2: colour is left for state, and an overlap is the one state
              that warrants it — named in words, never colour alone. */}
          <Show when={cue === 'is-overlap'}>
            <span class="worklog-overlap">{m.tracking_class_overlap()}</span>
          </Show>
        </span>
      )
    }

    if (colKey === 'description') {
      return (
        <span class="worklog-desc">
          {part('description', () => displayCell(entry, 'description'), 'cell-trunc')}
          {/* Befund 8: a ticket sits under its description rather than in a
              column that stands empty in every row of most ranges. */}
          {/* The ticket is editable here too — it had no target at all before.
              In read mode it stays a link with the flat view's activation rule:
              a plain click belongs to the cell and starts editing, following the
              link takes Ctrl/⌘. An empty ticket still offers a target, otherwise
              one could never be added in this view. */}
          <Show
            when={editor.isEditing(id, 'ticket')}
            fallback={
              <Show
                when={str(row.ticket) !== ''}
                fallback={
                  <span
                    class="worklog-part worklog-ticket is-empty"
                    title={m.tracking_edit_hint_part({ field: FIELD_BY_KEY.get('ticket')?.label() ?? 'Ticket' })}
                    onDblClick={(event) => { event.stopPropagation(); editor.beginEdit(id, 'ticket') }}
                  >{m.worklog_add_ticket()}</span>
                }
              >
                <a
                  class="worklog-ticket ticket-link"
                  href={ticketUrlFor(str(row.ticket), num(row.project))}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={m.tracking_ticket_link_hint()}
                  onClick={(event) => {
                    if (!event.ctrlKey && !event.metaKey) {
                      event.preventDefault()
                    }
                  }}
                  onDblClick={(event) => { event.stopPropagation(); editor.beginEdit(id, 'ticket') }}
                >{str(row.ticket)}</a>
              </Show>
            }
          >
            <span class="worklog-part-edit">
              <span class="inline-ghost" aria-hidden="true">{str(row.ticket)}</span>
              <InlineEditor
                field={FIELD_BY_KEY.get('ticket')!}
                label={FIELD_BY_KEY.get('ticket')?.label() ?? 'Ticket'}
                initial={editor.draftValue(id, 'ticket') ?? ''}
                seed={editor.seedChar()}
                options={optionLookup}
                onCommit={editor.commitCell}
                onCancel={editor.cancelCell}
              />
            </span>
          </Show>
        </span>
      )
    }

    if (colKey === 'duration') {
      const barWidth = (minutes: number): string => `${Math.max(3, Math.round(minutes * DURATION_BAR_PX_PER_MINUTE))}px`
      const agentHalf = pairedAgentOf(entry)

      return (
        <span class="worklog-duration">
          {/* The figure is the data; the bar is a second, non-essential encoding
              of it (WCAG 1.4.1) and is hidden from assistive technology. */}
          <span class="worklog-duration-line">
            <span
              class="duration-bar"
              classList={{ 'is-agent': entry.source === 'agent' }}
              aria-hidden="true"
              style={{ '--duration-width': barWidth(entry.durationMinutes) }}
            />
            <span class="num worklog-duration-value">
              <Show when={entry.estimated}><span class="duration-estimated" title={m.worklog_estimated_hint()}>≈ </span></Show>
              {entry.duration}
            </span>
          </span>
          {/* The agent half of the pair: its own hatched bar and its own figure,
              never added to the human one (ADR-025 §7). */}
          <Show when={agentHalf !== undefined}>
            <span class="worklog-duration-line is-agent-line">
              <span class="duration-bar is-agent" aria-hidden="true" style={{ '--duration-width': barWidth(agentHalf!.durationMinutes) }} />
              <span class="num worklog-duration-agent">{m.worklog_agent_duration({ duration: agentHalf!.duration })}</span>
            </span>
          </Show>
        </span>
      )
    }

    return cellContent(entry, colKey)
  }

  // One worklog row, shared by every view: the flat grid and the grouped day
  // sections render the SAME <tr>, so inline editing, gridNav and the row cues
  // behave identically in both and cannot drift apart.
  const renderRow = (entry: TrackingEntry, previous?: () => TrackingEntry | undefined): JSX.Element => {
                  const id = num(entry.id)
                  // First row of its block? Only that row prints the context; the
                  // rest render an empty cell whose top border is suppressed, so
                  // the block reads as one area without a rowspan — which would
                  // break gridNav's cellIndex arithmetic (design review, Befund 3).
                  const startsBlock = (): boolean => {
                    const before = previous?.()

                    const byContext = sort() === 'context'

                    return before === undefined || blockKey(before, byContext) !== blockKey(entry, byContext)
                  }

                  return (
                    <>
                    {/* Labeled band marking where future entries end and today/past
                        begin — a non-colour cue paired with the .is-future tint.
                        A non-data row (grid-divider) so keyboard nav skips it, but it
                        stays in the a11y tree so the "future" label reaches AT. */}
                    <Show when={futureDividerBeforeId() === id}>
                      <tr class="tracking-divider grid-divider">
                        <td colspan={visibleColumns().length + 1}>{m.tracking_future_divider()}</td>
                      </tr>
                    </Show>
                    <tr class={`tracking-row ${id <= 0 ? 'is-new' : rowCues().get(id) ?? ''}`.trimEnd()} classList={{ 'is-dirty': editor.isDirty(id), 'is-future': rowIsFuture(entry) }} aria-busy={editor.savingRows[id] ? 'true' : undefined}>
                      <For each={visibleColumns()}>
                        {(col) => {
                          // In the grouped view a composite cell is editable through its
                          // primary field; elsewhere the column key IS the field key.
                          const fieldKey = view() === 'grouped' ? (COMPOSITE_PRIMARY_FIELD[col.key] ?? col.key) : col.key
                          const editable = FIELD_BY_KEY.has(fieldKey)
                          const fieldType = FIELD_BY_KEY.get(col.key)?.type
                          // Single-line editors overlay a hidden ghost of the value
                          // (below) so opening one can't re-flow the auto-layout column.
                          const overlayEditor = fieldType !== undefined && INLINE_OVERLAY_TYPES.has(fieldType)

                          return (
                            <td
                              classList={{
                                numeric: col.numeric,
                                'is-editable': editable,
                                'is-invalid': editor.fieldInvalid(id, col.key),
                                // The block cell only shows its content on the block's
                                // first row; the continuation cells drop the top border
                                // so the block reads as one area (Befund 3).
                                'worklog-block-cell': view() === 'grouped' && col.key === 'context',
                                'is-continuation': view() === 'grouped' && col.key === 'context' && !startsBlock(),
                              }}
                              data-row-id={String(id)}
                              data-col-key={col.key}
                              data-inline-editing={editor.isEditing(id, col.key) || (view() === 'grouped' && COMPOSITE_PARTS[col.key]?.some((key) => editor.isEditing(id, key))) ? '' : undefined}
                              title={col.key === 'date' ? displayDate(str(editor.overlayRow(entry).date)) : undefined}
                              onDblClick={() => { if (editable) editor.beginEdit(id, fieldKey) }}
                            >
                              <Show
                                when={editor.isEditing(id, col.key)}
                                fallback={view() === 'grouped'
                                  ? groupedCell(entry, col.key, startsBlock)
                                  : cellContent(entry, col.key)}
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
                      <td class="tracking-row-actions" data-col-key="actions" data-row-id={String(id)}>
                        <div class="row-actions">
                          {/* Full button set — shown at comfortable widths. Under width
                              pressure (.is-thin-2) it collapses into the kebab menu below,
                              which stays visible at every level (unlike the pre-menu
                              behaviour of hiding the whole column). */}
                          <span class="action-set">
                            {/* Per-row Continue / Prolong / Info — only for saved entries. */}
                            <Show when={id > 0}>
                              <RowAction label={m.tracking_continue()} keyshortcut={rowShortcut('Alt+C')} onClick={() => continueEntry(entry)}><ContinueIcon /></RowAction>
                              <RowAction label={m.tracking_info()} keyshortcut={rowShortcut('Alt+I')} onClick={() => void showInfo(entry)}><InfoIcon /></RowAction>
                              {/* Prolong rewrites the row's end to now — only meaningful on the
                                  LATEST entry; on an older row it would silently overwrite a past
                                  end with the current time, so it's hidden there. */}
                              <Show when={isLatestEntry(entry)}>
                                <RowAction label={m.tracking_prolong()} keyshortcut={rowShortcut('Alt+P')} onClick={() => void prolongLast(entry)}><ProlongIcon /></RowAction>
                              </Show>
                            </Show>
                            <RowAction label={m.admin_delete()} danger onClick={() => setPendingDelete(entry)}><TrashIcon /></RowAction>
                          </span>
                          {/* Collapsed variant: one always-visible kebab, opening on hover
                              (pointer) or click/tap (touch, keyboard). Save/Reset stay OUTSIDE
                              the menu — mid-entry saving must not cost an extra click. */}
                          <span
                            class="action-menu"
                            onPointerEnter={(event) => { if (event.pointerType === 'mouse') { cancelActionsMenuClose(); setActionsMenuRow(id) } }}
                            onPointerLeave={(event) => { if (event.pointerType === 'mouse' && actionsMenuRow() === id) { scheduleActionsMenuClose(id) } }}
                            onKeyDown={(event) => { if (event.key === 'Escape') { closeActionsMenu() } }}
                          >
                            <button
                              type="button"
                              class="link-button is-icon"
                              aria-haspopup="menu"
                              aria-expanded={actionsMenuRow() === id}
                              aria-label={m.tracking_row_actions()}
                              title={m.tracking_row_actions()}
                              onClick={() => setActionsMenuRow((current) => (current === id ? null : id))}
                            >
                              <KebabIcon />
                            </button>
                            <Show when={actionsMenuRow() === id}>
                              <div class="action-menu-pop" role="menu" ref={positionActionsMenu}>
                                <Show when={id > 0}>
                                  <button type="button" role="menuitem" onClick={() => { closeActionsMenu(); continueEntry(entry) }}><ContinueIcon /> {m.tracking_continue()}</button>
                                  <button type="button" role="menuitem" onClick={() => { closeActionsMenu(); void showInfo(entry) }}><InfoIcon /> {m.tracking_info()}</button>
                                  <Show when={isLatestEntry(entry)}>
                                    <button type="button" role="menuitem" onClick={() => { closeActionsMenu(); void prolongLast(entry) }}><ProlongIcon /> {m.tracking_prolong()}</button>
                                  </Show>
                                </Show>
                                <button type="button" role="menuitem" class="is-danger" onClick={() => { closeActionsMenu(); setPendingDelete(entry) }}><TrashIcon /> {m.admin_delete()}</button>
                              </div>
                            </Show>
                          </span>
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
                        <td colspan={visibleColumns().length + 1}>
                          <span role="alert" class="form-status is-error">{editor.rowErrors[id]}</span>
                        </td>
                      </tr>
                    </Show>
                    </>
                  )
  }

  // Befund 7: "+ Eintrag" is the only primary action and stays with the content.
  // Only what names the page stays with the content.
  const renderPageTitle = (): JSX.Element => (
    <div class="tracking-header">
      <div class="tracking-header-title">
        <h2>{m.tracking_title()}</h2>
        <span class="num tracking-header-range">{m.tracking_days_option({ count: String(days()) })}</span>
      </div>
    </div>
  )

  // Everything one does TO or WITH the grid — add, the key that explains the
  // bars, the range, the tools and the view — belongs together in the menu when
  // there is one. In the top-bar layout there is no menu, so it stays in the
  // tool line rather than vanishing.
  const renderMenuTools = (): JSX.Element => (
    <div class="tracking-toolbar">
      <div class="tracking-header-actions">
        {/* Befund 7 removes the legends from under the table; the canvas keeps a
            compact key beside the primary action, where it explains the bars at
            the moment you first look at them. */}
        <span class="tracking-key" aria-hidden="true">
          <span class="tracking-key-item"><span class="duration-bar tracking-key-bar" /> {m.worklog_total_human()}</span>
          <span class="tracking-key-item"><span class="duration-bar is-agent tracking-key-bar" /> {m.worklog_total_agent()}</span>
          <span class="tracking-key-item"><span class="duration-estimated">≈</span> {m.worklog_key_estimated()}</span>
        </span>
          <button type="button" class="primary-button is-icon" data-keyboard-add aria-keyshortcuts="Alt+A" aria-label={m.tracking_add()} title={m.tracking_add()} onClick={() => addEntry()}>
            <PlusIcon />
          </button>
      </div>
      {/* Befund 7: in the sidebar these read as a submenu under Worklog, so the
          two groups get headings there. In the tool line they are a single row
          and the headings would be noise — CSS shows them only in the sidebar. */}
          <p class="tracking-tools-heading" aria-hidden="true">{m.worklog_tools_tools()}</p>
          {/* Bulk entry uses ROLE_ADMIN-only presets — gate it like the (now removed) Extras page did. */}
          <Show when={canBulkEnter()}>
            <button type="button" class="action-button" onClick={() => setBulkOpen(true)}>{m.extras_title()}</button>
          </Show>
          {/* Reload the entries (Alt+R). Outside the admin gate — every user gets it. */}
          {/* Continue / Prolong / Info moved to per-row action icons; Alt+C/P/I
              still act on the keyboard-cursor row via the global shortcut handler. */}
          <a class="action-button is-icon" href={exportHref()} aria-keyshortcuts="Alt+X" aria-label={m.tracking_export()} title={m.tracking_export()}><DownloadIcon /></a>
          {/* Freetext + always-full preset menu: type any whole number of days
              (applyDays clamps + persists), or pick a preset — the menu always lists
              ALL presets regardless of what's typed, so switching ranges never needs
              clearing the field first. */}
          <p class="tracking-tools-heading" aria-hidden="true">{m.worklog_tools_range()}</p>
          <div class="tracking-days">
            <span id="tracking-days-lbl">{m.tracking_days_label()}</span>
            <div class="days-combo" ref={(el) => { daysComboRef = el }}>
              <input
                id="tracking-days-input"
                name="days"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                class="tracking-days-input"
                role="combobox"
                aria-labelledby="tracking-days-lbl"
                aria-expanded={daysMenuOpen()}
                aria-controls="tracking-days-menu"
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
                  if (event.key === 'ArrowDown') {
                    event.preventDefault()
                    if (daysMenuOpen()) { setDaysActiveIdx((i) => Math.min(DAYS_OPTIONS.length - 1, i + 1)) }
                    else { openDaysMenu() }
                  } else if (event.key === 'ArrowUp') {
                    event.preventDefault()
                    if (daysMenuOpen()) { setDaysActiveIdx((i) => Math.max(0, i - 1)) }
                  } else if (event.key === 'Enter' && daysMenuOpen() && daysActiveIdx() >= 0) {
                    event.preventDefault()
                    const option = DAYS_OPTIONS[daysActiveIdx()]
                    if (option !== undefined) { chooseDays(option) }
                  } else if (event.key === 'Escape' && daysMenuOpen()) {
                    event.preventDefault()
                    closeDaysMenu()
                  }
                }}
              />
              <button
                type="button"
                class="days-combo-toggle"
                tabindex="-1"
                aria-label={m.tracking_days_presets()}
                aria-expanded={daysMenuOpen()}
                aria-controls="tracking-days-menu"
                onClick={() => { if (daysMenuOpen()) { closeDaysMenu() } else { openDaysMenu() } }}
              >
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
              </button>
              <Show when={daysMenuOpen()}>
                <ul class="days-combo-menu" id="tracking-days-menu" aria-label={m.tracking_days_label()}>
                  <For each={DAYS_OPTIONS}>
                    {(option, index) => (
                      <li>
                        <button
                          type="button"
                          class="days-combo-option"
                          tabindex="-1"
                          classList={{ 'is-active': index() === daysActiveIdx() }}
                          aria-current={days() === option ? 'true' : undefined}
                          onClick={() => chooseDays(option)}
                          onPointerEnter={() => setDaysActiveIdx(index())}
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

          {/* View switch — the worklog's three presentations of the same rows.
              Sits in the tool line next to the range, because both narrow what the
              grid shows. */}
          <p class="tracking-tools-heading" aria-hidden="true">{m.worklog_view_label()}</p>
        <WorklogViewSwitch value={view()} onChange={chooseView} />

        {/* Ordering only has meaning where blocks exist, so it is offered with
            the grouped view and not as a setting that quietly does nothing. */}
        <Show when={view() === 'grouped'}>
          <p class="tracking-tools-heading" aria-hidden="true">{m.worklog_sort_label()}</p>
          <div class="worklog-view-switch" role="radiogroup" aria-label={m.worklog_sort_label()}>
            <For each={WORKLOG_SORTS}>
              {(option) => (
                <button
                  type="button"
                  role="radio"
                  class="worklog-view-option"
                  aria-checked={sort() === option ? 'true' : 'false'}
                  tabindex={sort() === option ? 0 : -1}
                  onClick={() => chooseSort(option)}
                >{option === 'time' ? m.worklog_sort_time() : m.worklog_sort_context()}</button>
              )}
            </For>
          </div>
        </Show>

    </div>
  )

  // The inline-edit hint explains the grid, so it stays with the content whichever
  // layout is live.
  const renderHint = (): JSX.Element => (
    <p class="tracking-hint">{m.tracking_edit_hint()}</p>
  )

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
        <p class="save-toast" aria-hidden="true">{savedNotice()}</p>
      </Show>

      {/* The toolbar MOVES into the left sidebar when that layout is active —
          one instance, never two: a mirrored copy would duplicate every label and
          make the focus order ambiguous. In the default top-bar layout there is no
          sidebar, so it stays here rather than disappearing. */}
      {renderPageTitle()}
      <Show when={navSideLayout() && toolsSlot()} fallback={renderMenuTools()}>
        <Portal mount={toolsSlot()!}>{renderMenuTools()}</Portal>
      </Show>
      {renderHint()}

      {/* A session-expiry refetch errors too, but the overlay owns that — keep the
          last-good grid (and the user's drafts) visible+dimmed behind it, not a
          jarring "load error". A genuine error (session OK) still shows the fallback. */}
      <Show when={!entries.isError || sessionExpired()} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <Show when={view() !== 'timeline'} fallback={
          <WorklogTimeline
            days={groupKeys()}
            entriesFor={(day) => entriesByGroup().get(day) ?? []}
            formatDay={displayDate}
            formatTotal={(day) => `${m.worklog_total_human()} ${formatDuration(groupFacts(day).human)}`}
            onJump={jumpToEntry}
          />
        }>
        <div class="table-scroll" ref={setScrollEl}>
          <table
            class="data-table tracking-table"
            classList={{ 'is-fetching': entries.isFetching, 'is-grouped': view() === 'grouped' }}
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
                <For each={visibleColumns()}>
                  {(col) => (
                    <th scope="col" data-col-key={col.key} classList={{ numeric: col.numeric }}>
                      <ColumnHeader label={col.label()} icon={col.key === 'date' ? <CalendarIcon /> : undefined} />
                    </th>
                  )}
                </For>
                <th scope="col" data-col-key="actions"><ColumnHeader label={m.tracking_actions()} icon={<ToolsIcon />} /></th>
              </tr>
            </thead>
            <Show
              when={view() === 'grouped'}
              fallback={<tbody><For each={rows()}>{(entry) => renderRow(entry)}</For></tbody>}
            >
              {/* One <tbody> per day — real table semantics, no rowspan, so gridNav
                  keeps working on cellIndex. The day heading is a rowgroup header
                  row marked grid-divider, so keyboard nav skips it while it stays
                  in the a11y tree. */}
              <For each={groupKeys()}>
                {(key) => (
                  <tbody class="worklog-day">
                    <tr class="worklog-day-head grid-divider">
                      <th scope="rowgroup" colspan={visibleColumns().length + 1}>
                        {/* The flex layout lives on an inner element: `display: flex`
                            on a <th> stops it being a table-cell, and the colspan is
                            then ignored — the heading collapsed to column one. */}
                        <span class="worklog-day-headline">
                        <span class="worklog-day-date">{groupLabel(key)}</span>
                        <Show when={groupFacts(key).estimated > 0}>
                          <span class="worklog-day-estimated">
                            {m.worklog_day_estimated({ count: String(groupFacts(key).estimated), total: String(groupFacts(key).humanRows) })}
                          </span>
                        </Show>
                        <span class="worklog-day-total">
                          <span class="worklog-total-human">{m.worklog_total_human()} {formatDuration(groupFacts(key).human)}</span>
                          <Show when={groupFacts(key).agent > 0}>
                            <span class="worklog-total-agent">{m.worklog_total_agent()} {formatDuration(groupFacts(key).agent)}</span>
                          </Show>
                        </span>
                        </span>
                      </th>
                    </tr>
                    <For each={entriesByGroup().get(key) ?? []}>
                      {(entry, index) => renderRow(entry, () => (index() > 0 ? (entriesByGroup().get(key) ?? [])[index() - 1] : undefined))}
                    </For>
                  </tbody>
                )}
              </For>
            </Show>
          </table>
        </div>
        </Show>

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
        <p class="dialog-body">{(pendingDelete()?.pairedEntry ?? null) !== null ? m.tracking_delete_body_paired() : m.tracking_delete_body()}</p>
        <div class="form-actions">
          <button type="button" class="primary-button is-danger" onClick={() => void confirmDelete()}>{m.tracking_delete_confirm()}</button>
          <button type="button" class="action-button" onClick={() => setPendingDelete(null)}>{m.admin_cancel()}</button>
        </div>
      </PageDialog>
    </section>
  )
}
