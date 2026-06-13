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

// Dropdown sources for the billing form — all ExtJS-style row-wrapped.
export interface UserRecord {
  user: { id: number; username: string }
}
export interface ProjectRecord {
  project: { id: number; name: string }
}
export interface CustomerRecord {
  customer: { id: number; name: string }
}
export interface PresetRecord {
  preset: { id: number; name: string }
}

export interface NamedOption {
  id: number
  label: string
}

export function usersQuery() {
  return {
    queryKey: ['all-users'] as const,
    queryFn: () => getJson<UserRecord[]>('/getAllUsers'),
    select: (records: UserRecord[]): NamedOption[] =>
      records.map((r) => ({ id: r.user.id, label: r.user.username })),
  }
}

export function projectsQuery() {
  return {
    queryKey: ['all-projects'] as const,
    queryFn: () => getJson<ProjectRecord[]>('/getAllProjects'),
    select: (records: ProjectRecord[]): NamedOption[] =>
      records.map((r) => ({ id: r.project.id, label: r.project.name })),
  }
}

export function customersQuery() {
  return {
    queryKey: ['all-customers'] as const,
    queryFn: () => getJson<CustomerRecord[]>('/getAllCustomers'),
    select: (records: CustomerRecord[]): NamedOption[] =>
      records.map((r) => ({ id: r.customer.id, label: r.customer.name })),
  }
}

export function presetsQuery() {
  return {
    queryKey: ['all-presets'] as const,
    queryFn: () => getJson<PresetRecord[]>('/getAllPresets'),
    select: (records: PresetRecord[]): NamedOption[] =>
      records.map((r) => ({ id: r.preset.id, label: r.preset.name })),
  }
}

// --- Interpretation ("Auswertung") ---

export interface TeamRecord {
  team: { id: number; name: string }
}
export interface ActivityRecord {
  activity: { id: number; name: string }
}

export function teamsQuery() {
  return {
    queryKey: ['all-teams'] as const,
    queryFn: () => getJson<TeamRecord[]>('/getAllTeams'),
    select: (records: TeamRecord[]): NamedOption[] =>
      records.map((r) => ({ id: r.team.id, label: r.team.name })),
  }
}

export function activitiesQuery() {
  return {
    queryKey: ['all-activities'] as const,
    queryFn: () => getJson<ActivityRecord[]>('/getActivities'),
    select: (records: ActivityRecord[]): NamedOption[] =>
      records.map((r) => ({ id: r.activity.id, label: r.activity.name })),
  }
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
  return {
    queryKey: ['interpretation', group, filters] as const,
    queryFn: () => getJson<GroupRow[]>(`/interpretation/${group}`, filterParams(filters)),
    enabled: hasInterpretationCriteria(filters),
  }
}

export function timeSeriesQuery(filters: InterpretationFilters) {
  return {
    queryKey: ['interpretation', 'time', filters] as const,
    queryFn: () => getJson<WorktimeRecord[]>('/interpretation/time', filterParams(filters)),
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
  return {
    queryKey: ['interpretation', 'entries', filters] as const,
    queryFn: () => getJson<EntryRecord[]>('/interpretation/entries', filterParams(filters)),
    enabled: hasInterpretationCriteria(filters),
  }
}
