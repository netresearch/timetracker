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
