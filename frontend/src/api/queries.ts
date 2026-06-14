import { getJson } from './client'

// Response shape of GET /interpretation/time (GroupByWorktimeAction):
// one record per day with booked time, `name` formatted as 'yy-mm-dd'.
export interface WorktimeRecord {
  id: null
  name: string
  day: string
  hours: number
  quota: string
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

export interface NamedOption {
  id: number
  label: string
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

type OptionRow = Record<string, { id: number; name?: string; username?: string }>

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
        .map((record) => record[rowKey])
        .filter((inner): inner is NonNullable<typeof inner> => inner != null)
        .map((inner) => ({ id: inner.id, label: String(inner[nameField] ?? '') })),
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

// The backend only accepts a query when at least one of customer/project/
// user/ticket (or year+month) is set; otherwise it answers 406. Mirror that
// so we don't fire requests that can only fail.
export function hasInterpretationCriteria(filters: InterpretationFilters): boolean {
  return filters.customer > 0 || filters.project > 0 || filters.user > 0 || filters.ticket.trim() !== ''
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
  }
}
