import { keepPreviousData, type QueryClient } from '@tanstack/solid-query'

import { getJson } from './client'
import { dmyToIso } from '../lib/timeParse'

// Response shape of GET /interpretation/time (GroupByWorktimeAction):
// one record per day with booked time, `name` formatted as 'yy-mm-dd'.
export interface WorktimeRecord {
  id: null
  name: string
  day: string
  hours: number
  quota: string
  /** Expected (Soll) hours for the day, from the contract or a 5×8h default. */
  expected: number
}

// Response shape of GET /getHolidays (GetHolidaysAction) — ExtJS-style
// row wrapping, `date` formatted as 'YYYY-MM-DD'.
export interface HolidayRecord {
  holiday: {
    name: string
    date: string
  }
}

export function monthTimesQuery(year: number, month: number, userId: number) {
  return {
    queryKey: ['interpretation-time', year, month, userId] as const,
    queryFn: () =>
      getJson<WorktimeRecord[]>('/interpretation/time', {
        year,
        month,
        user: userId,
        limit: 100,
      }),
  }
}

export function holidaysQuery(year: number, month: number) {
  return {
    queryKey: ['holidays', year, month] as const,
    queryFn: () => getJson<HolidayRecord[]>('/getHolidays', { year, month }),
  }
}

// Response shape of GET /getContractHours (GetContractHoursAction): the current
// user's per-weekday contract hours for the queried month, keyed hours_0 (Sunday)
// … hours_6 (Saturday) to match JS Date.getDay(). The backend already falls back
// to 8h per day when the user has no contract for that month.
export interface ContractHoursRecord {
  hours_0: number
  hours_1: number
  hours_2: number
  hours_3: number
  hours_4: number
  hours_5: number
  hours_6: number
}

export function contractHoursQuery(year: number, month: number) {
  return {
    queryKey: ['contract-hours', year, month] as const,
    queryFn: () => getJson<ContractHoursRecord>('/getContractHours', { year, month }),
  }
}

export interface NamedOption {
  id: number
  label: string
  /** Bookable flag where the source carries one (e.g. customers); undefined means
   *  "always bookable" (sources with no active concept). Drives the active-only picker. */
  active?: boolean
}

// Reference dropdown sources (customers/projects/users/teams/presets/ticket
// systems/activities) are ExtJS-style row-wrapped ([{<rowKey>: {id, name}}])
// and rarely change within a session, so they share a long staleTime to avoid
// refetching on every window refocus.
const REFERENCE_STALE_TIME = 5 * 60_000

/**
 * Cache key for an entity's reference/option source. The admin grid caches the
 * same endpoint under ['admin-list', entity]; the dropdowns and relation
 * columns read from this separate ['all-<entity>'] cache, so a mutation must
 * invalidate both (see AdminCrudShell). The entity name matches the admin
 * EntityDescriptor.key, which is why a save can derive this key.
 */
export function optionSourceKey(entity: string): readonly [string] {
  return [`all-${entity}`] as const
}

type OptionRow = Record<string, { id: number; name?: string; username?: string; active?: boolean | number | string }>

// The backend serializes `active` via a PHP (bool) cast → JSON true/false, but be
// defensive about drivers/serializers that emit 1/0 or "1"/"0": treat only the
// explicit falsy forms as inactive, everything else (incl. absent) as bookable.
/** True unless the value is an explicit "off" form (false / 0 / '0' / 'false');
 *  undefined → true ("no active concept"). Coerces the loose flags a PHP/Doctrine
 *  backend may emit so active-only filters can't be bypassed by a stringified 0. */
export function coerceActive(raw: unknown): boolean {
  return raw !== false && raw !== 0 && raw !== '0' && raw !== 'false'
}

function optionSourceQuery(
  entity: string,
  endpoint: string,
  rowKey: string,
  nameField: 'name' | 'username' = 'name',
) {
  return () => ({
    queryKey: optionSourceKey(entity),
    queryFn: () => getJson<OptionRow[]>(endpoint),
    select: (records: OptionRow[]): NamedOption[] =>
      records
        .map((record) => record?.[rowKey])
        .filter((inner): inner is NonNullable<typeof inner> => inner != null)
        .map((inner) => ({ id: inner.id, label: String(inner[nameField] ?? ''), active: inner.active === undefined ? undefined : coerceActive(inner.active) })),
    staleTime: REFERENCE_STALE_TIME,
  })
}

export const usersQuery = optionSourceQuery('users', '/getAllUsers', 'user', 'username')
export const projectsQuery = optionSourceQuery('projects', '/getAllProjects', 'project')
export const customersQuery = optionSourceQuery('customers', '/getAllCustomers', 'customer')
export const presetsQuery = optionSourceQuery('presets', '/getAllPresets', 'preset')
export const teamsQuery = optionSourceQuery('teams', '/getAllTeams', 'team')
export const ticketSystemsQuery = optionSourceQuery('ticketsystems', '/getTicketSystems', 'ticketSystem')
export const activitiesQuery = optionSourceQuery('activities', '/getActivities', 'activity')
// The work-log grid serves every user, so it uses the open /getCustomers
// (user-scoped) rather than the ROLE_ADMIN /getAllCustomers.
export const trackingCustomersQuery = optionSourceQuery('tracking-customers', '/getCustomers', 'customer')

// ── Time-tracking work-log entries (the /ui/tracking grid) ───────────────────
// GET /getData/days/{days} returns ExtJS-style row-wrapped entries; the grid
// reads the unwrapped list. duration + class are server-derived — class drives
// row styling (EntryClass: PLAIN=1, DAYBREAK=2, PAUSE=4, OVERLAP=8).
export interface TrackingEntry {
  id: number
  date: string | null
  start: string | null
  end: string | null
  user: number | null
  customer: number | null
  project: number | null
  activity: number | null
  description: string
  ticket: string
  duration: string
  durationMinutes: number
  class: number
  worklog: number | null
  extTicket: string | null
}

interface TrackingEntryRow {
  entry: TrackingEntry
}

// The cache key prefix the work-log grid (and every save/delete/refresh) keys on;
// the per-range query appends the day count. Exported so the page invalidates and
// seeds the same key.
export const ENTRIES_KEY = 'tracking-entries'

// The /tracking/save response envelope: the persisted entry, with date as 'd/m/Y'
// and start/end as 'H:i' (already the cache row shape), so it can seed the cache
// directly. ticket/description/extTicket are present only when non-empty.
export interface SavedEntryResult {
  result: {
    id: number
    date: string
    start: string
    end: string
    user: number
    customer: number
    project: number
    activity: number
    duration: string
    durationMinutes: number
    class: number
    ticket?: string
    description?: string
    extTicket?: string
  }
}

// Build a cache row from a save response. A created entry must land in entries.data
// the instant its save returns 200 — not on a follow-up refetch — so it survives a
// session-expiry (issue #408) or any other error on that refetch.
function savedEntryToRow(saved: SavedEntryResult['result']): TrackingEntryRow {
  return {
    entry: {
      id: saved.id,
      date: saved.date,
      start: saved.start,
      end: saved.end,
      user: saved.user,
      customer: saved.customer,
      project: saved.project,
      activity: saved.activity,
      description: saved.description ?? '',
      ticket: saved.ticket ?? '',
      duration: saved.duration,
      durationMinutes: saved.durationMinutes,
      class: saved.class,
      worklog: null,
      extTicket: saved.extTicket ?? null,
    },
  }
}

// Upsert a just-saved entry into every cached entries range (keyed by ENTRIES_KEY)
// so the grid shows it immediately and keeps it even if the reconciling refetch
// fails. The save response is authoritative for the saved row; neighbour rows
// (re-classed server-side) are reconciled by the follow-up invalidate, but losing
// that refetch must never drop the user's just-saved work.
export function upsertSavedEntry(queryClient: QueryClient, saved: SavedEntryResult['result']): void {
  const row = savedEntryToRow(saved)
  queryClient.setQueriesData<TrackingEntryRow[]>({ queryKey: [ENTRIES_KEY] }, (existing) => {
    if (existing === undefined) {
      return existing
    }
    const without = existing.filter((candidate) => candidate?.entry?.id !== saved.id)

    return [...without, row]
  })
}

// A chronological sort key from the row format: date is 'd/m/Y', so reorder it
// to 'Y-m-d' (lexically = chronological) and append start ('H:i') as the
// tiebreaker. A blank/non-matching date sorts to the end of its direction.
function entrySortKey(entry: TrackingEntry): string {
  const iso = dmyToIso(entry.date ?? '') ?? (entry.date ?? '')

  return `${iso} ${entry.start ?? ''}`
}

export function trackingEntriesQuery(days: number) {
  return {
    queryKey: ['tracking-entries', days] as const,
    queryFn: () => getJson<TrackingEntryRow[]>(`/getData/days/${days}`),
    // Newest entry first. The backend (getEntriesByUser) returns day/start
    // ASCending (shared with the legacy ExtJS grid + export), so the new grid
    // sorts client-side instead — which also makes [0] the latest entry, as
    // Add (suggest-start) / Prolong-last / Continue all assume.
    select: (rows: TrackingEntryRow[]): TrackingEntry[] =>
      rows
        .map((row) => row?.entry)
        .filter((entry): entry is TrackingEntry => entry != null)
        .sort((a, b) => {
          const ka = entrySortKey(a)
          const kb = entrySortKey(b)
          if (ka !== kb) {
            return ka < kb ? 1 : -1
          }

          // Same date+start: order by id descending so [0] is deterministically
          // the most recently created entry — the one "latest" (suggest-start,
          // Prolong-last, Continue, the Prolong icon's latest-only gate) means.
          return Number(b.id ?? 0) - Number(a.id ?? 0)
        }),
    // Keep the current rows visible while switching the days range (no flicker).
    placeholderData: keepPreviousData,
  }
}

// Projects with the fields the work-log grid needs beyond {id,label}: the
// customer (for cascading the project dropdown) and jiraId (for ticket→project
// mapping). Open to all users (/getAllProjects is IS_AUTHENTICATED_FULLY).
export interface TrackingProject {
  id: number
  name: string
  customer: number
  active: boolean
  jiraId: string
  ticketSystem: number
}

export function trackingProjectsQuery() {
  return {
    queryKey: ['tracking-projects'] as const,
    queryFn: () => getJson<{ project?: Record<string, unknown> }[]>('/getAllProjects'),
    select: (rows: { project?: Record<string, unknown> }[]): TrackingProject[] =>
      rows
        .map((row) => row?.project)
        .filter((project): project is Record<string, unknown> => project != null)
        .map((project) => ({
          id: Number(project.id ?? 0),
          name: String(project.name ?? ''),
          customer: Number(project.customer ?? 0),
          // Default to bookable unless the backend explicitly says inactive.
          active: coerceActive(project.active),
          jiraId: String(project.jiraId ?? project.jira_id ?? ''),
          ticketSystem: Number(project.ticket_system ?? project.ticketSystem ?? 0),
        })),
    staleTime: REFERENCE_STALE_TIME,
  }
}

// Ticket systems with their URL pattern (a "%s" placeholder), for linking a
// ticket to its system. Open to all users (/getTicketSystems).
export interface TrackingTicketSystem {
  id: number
  ticketUrl: string
}

export function trackingTicketSystemsQuery() {
  return {
    queryKey: ['tracking-ticketsystems'] as const,
    queryFn: () => getJson<{ ticketSystem?: Record<string, unknown> }[]>('/getTicketSystems'),
    select: (rows: { ticketSystem?: Record<string, unknown> }[]): TrackingTicketSystem[] =>
      rows
        .map((row) => row?.ticketSystem)
        .filter((system): system is Record<string, unknown> => system != null)
        .map((system) => ({
          id: Number(system.id ?? 0),
          ticketUrl: String(system.ticketUrl ?? system.ticketurl ?? ''),
        })),
    staleTime: REFERENCE_STALE_TIME,
  }
}

/** Summary scope row from POST /getSummary (minutes for total/own/estimation). */
export interface SummaryScope {
  scope: string
  name: string
  entries: number
  total: number
  own: number
  estimation: number
}

/** Shared filter shape for every interpretation view. */
export interface InterpretationFilters {
  datestart: string
  dateend: string
  customer: number
  project: number
  team: number
  user: number
  activity: number
  ticket: string
  description: string
}

/** A grouped-effort row: name + hours, with a preformatted quota string. */
export interface GroupRow {
  id: number | null
  name: string
  hours: number
  quota: string
}

export type InterpretationGroup = 'customer' | 'project' | 'ticket' | 'activity' | 'user'

// The backend only accepts a query when at least one real filter dimension is
// set (customer/project/team/user/activity/ticket/description); otherwise it
// answers 406. Mirror that so we don't fire requests that can only fail.
export function hasInterpretationCriteria(filters: InterpretationFilters): boolean {
  return (
    filters.customer > 0 ||
    filters.project > 0 ||
    filters.team > 0 ||
    filters.user > 0 ||
    filters.activity > 0 ||
    filters.ticket.trim() !== '' ||
    filters.description.trim() !== ''
  )
}

function filterParams(filters: InterpretationFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {}
  if (filters.datestart) params.datestart = filters.datestart
  if (filters.dateend) params.dateend = filters.dateend
  if (filters.customer > 0) params.customer = filters.customer
  if (filters.project > 0) params.project = filters.project
  if (filters.team > 0) params.team = filters.team
  if (filters.user > 0) params.user = filters.user
  if (filters.activity > 0) params.activity = filters.activity
  if (filters.ticket.trim() !== '') params.ticket = filters.ticket.trim()
  if (filters.description.trim() !== '') params.description = filters.description.trim()

  return params
}

export function groupQuery(group: InterpretationGroup, filters: InterpretationFilters) {
  // Key on the normalized request (filterParams drops zero/empty/irrelevant
  // fields) so filter changes that don't alter the actual request reuse the
  // cache instead of refetching all seven interpretation queries.
  const params = filterParams(filters)

  return {
    queryKey: ['interpretation', group, params] as const,
    queryFn: () => getJson<GroupRow[]>(`/interpretation/${group}`, params),
    enabled: hasInterpretationCriteria(filters),
  }
}

export function timeSeriesQuery(filters: InterpretationFilters) {
  const params = filterParams(filters)

  return {
    queryKey: ['interpretation', 'time', params] as const,
    queryFn: () => getJson<WorktimeRecord[]>('/interpretation/time', params),
    enabled: hasInterpretationCriteria(filters),
  }
}

export interface EntryRecord {
  entry: {
    id: number
    date: string | null
    start: string | null
    end: string | null
    customer: number | null
    project: number | null
    activity: number | null
    description: string
    ticket: string
    duration: string
    quota: string
  }
}

export function lastEntriesQuery(filters: InterpretationFilters) {
  const params = filterParams(filters)

  return {
    queryKey: ['interpretation', 'entries', params] as const,
    queryFn: () => getJson<EntryRecord[]>('/interpretation/entries', params),
    enabled: hasInterpretationCriteria(filters),
    // Keep the previous rows visible while a filter change refetches, so the
    // grid stays mounted (its keyboard state/focus survive) instead of being
    // unmounted by the loading branch.
    placeholderData: keepPreviousData,
  }
}
