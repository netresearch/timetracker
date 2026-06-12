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
