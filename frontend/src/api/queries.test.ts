import { QueryClient } from '@tanstack/solid-query'
import { describe, expect, it } from 'vitest'

import {
  activitiesQuery,
  customersQuery,
  ENTRIES_KEY,
  groupQuery,
  hasInterpretationCriteria,
  type InterpretationFilters,
  optionSourceKey,
  type SavedEntryResult,
  ticketSystemsQuery,
  type TrackingEntry,
  upsertSavedEntry,
  usersQuery,
} from './queries'

describe('upsertSavedEntry (ADR-025 attribution in the cached row)', () => {
  const saved: SavedEntryResult['result'] = {
    id: 7, date: '15/01/2024', start: '09:00', end: '10:30', user: 1, customer: 1, project: 4, activity: 5,
    duration: '01:30', durationMinutes: 90, class: 0, ticket: 'ABC-1', description: 'corrected',
  }
  const cachedRow = (entry: Partial<TrackingEntry>): { entry: TrackingEntry } => ({
    entry: {
      id: 7, date: '15/01/2024', start: '09:00', end: '10:00', user: 1, customer: 1, project: 4, activity: 5,
      description: 'before', ticket: 'ABC-1', duration: '01:00', durationMinutes: 60, class: 0, worklog: null,
      extTicket: null, source: 'human', estimated: false, ...entry,
    },
  })
  const upsertInto = (rows: { entry: TrackingEntry }[]): TrackingEntry | undefined => {
    const queryClient = new QueryClient()
    queryClient.setQueryData([ENTRIES_KEY, 7], rows)
    upsertSavedEntry(queryClient, saved)

    return queryClient.getQueryData<{ entry: TrackingEntry }[]>([ENTRIES_KEY, 7])?.find((row) => row.entry.id === 7)?.entry
  }

  it('keeps an edited agent entry as agent time — the server does not relabel it', () => {
    const entry = upsertInto([cachedRow({ source: 'agent' })])
    expect(entry?.source).toBe('agent')
    expect(entry?.description).toBe('corrected')
    expect(entry?.end).toBe('10:30')
  })

  it('keeps the estimated flag of an edited agent entry — the server leaves it untouched', () => {
    const entry = upsertInto([cachedRow({ source: 'agent', estimated: true })])
    expect(entry?.source).toBe('agent')
    expect(entry?.estimated).toBe(true)
  })

  it('finds the previous row in another cached range when the entry moves into this one', () => {
    const queryClient = new QueryClient()
    queryClient.setQueryData([ENTRIES_KEY, 7], [])
    queryClient.setQueryData([ENTRIES_KEY, 35], [cachedRow({ source: 'agent' })])
    upsertSavedEntry(queryClient, saved)

    const moved = queryClient.getQueryData<{ entry: TrackingEntry }[]>([ENTRIES_KEY, 7])?.find((row) => row.entry.id === 7)?.entry
    expect(moved?.source).toBe('agent')
  })

  it('marks a confirmed delegated estimate as no longer estimated', () => {
    const entry = upsertInto([cachedRow({ source: 'human', estimated: true })])
    expect(entry?.source).toBe('human')
    expect(entry?.estimated).toBe(false)
  })

  it('carries row fields the save response does not return', () => {
    const entry = upsertInto([{ entry: { ...cachedRow({}).entry, ...({ pairedEntry: 8 } as Partial<TrackingEntry>) } }])
    expect((entry as unknown as Record<string, unknown>)?.pairedEntry).toBe(8)
  })

  it('adds a newly created entry as a plain human self-log', () => {
    const entry = upsertInto([])
    expect(entry?.source).toBe('human')
    expect(entry?.estimated).toBe(false)
  })
})

const base: InterpretationFilters = {
  datestart: '',
  dateend: '',
  customer: 0,
  project: 0,
  team: 0,
  user: 0,
  activity: 0,
  ticket: '',
  description: '',
}

describe('hasInterpretationCriteria', () => {
  it('is false for all-empty filters', () => {
    expect(hasInterpretationCriteria(base)).toBe(false)
  })

  it('is true when customer/project/user is set', () => {
    expect(hasInterpretationCriteria({ ...base, customer: 3 })).toBe(true)
    expect(hasInterpretationCriteria({ ...base, project: 3 })).toBe(true)
    expect(hasInterpretationCriteria({ ...base, user: 3 })).toBe(true)
  })

  it('treats a whitespace-only ticket as empty but a real one as criteria', () => {
    expect(hasInterpretationCriteria({ ...base, ticket: '   ' })).toBe(false)
    expect(hasInterpretationCriteria({ ...base, ticket: ' ABC-1 ' })).toBe(true)
  })

  it('counts team, activity and description as standalone criteria', () => {
    expect(hasInterpretationCriteria({ ...base, team: 2 })).toBe(true)
    expect(hasInterpretationCriteria({ ...base, activity: 5 })).toBe(true)
    expect(hasInterpretationCriteria({ ...base, description: 'meeting' })).toBe(true)
  })

  it('does not count dates alone', () => {
    expect(hasInterpretationCriteria({ ...base, datestart: '2026-01-01', dateend: '2026-01-31' })).toBe(false)
  })
})

describe('groupQuery params (filterParams via queryKey) + enabled', () => {
  it('omits zero/empty fields and trims ticket/description', () => {
    const q = groupQuery('customer', { ...base, customer: 3, ticket: ' X ', description: '  ', datestart: '2026-01-01' })
    expect(q.queryKey[2]).toEqual({ datestart: '2026-01-01', customer: 3, ticket: 'X' })
    expect(q.enabled).toBe(true)
  })

  it('disables the query when no criteria are set', () => {
    expect(groupQuery('user', base).enabled).toBe(false)
  })
})

describe('option-source select', () => {
  it('unwraps row-wrapped records to {id,label}', () => {
    expect(usersQuery().select([{ user: { id: 7, username: 'dev' } }])).toEqual([{ id: 7, label: 'dev' }])
    expect(customersQuery().select([{ customer: { id: 1, name: 'ACME' } }])).toEqual([{ id: 1, label: 'ACME' }])
    expect(activitiesQuery().select([{ activity: { id: 5, name: 'Dev' } }])).toEqual([{ id: 5, label: 'Dev' }])
  })

  it('drops rows whose wrapper is missing/null', () => {
    expect(customersQuery().select([{ customer: { id: 1, name: 'ACME' } }, { customer: null } as never, {} as never]))
      .toEqual([{ id: 1, label: 'ACME' }])
  })

  it('gives reference sources a long staleTime', () => {
    expect(customersQuery().staleTime).toBeGreaterThanOrEqual(60_000)
  })

  // AdminCrudShell invalidates optionSourceKey(descriptor.key) after a save so
  // dropdowns/relation columns refresh. That only works if the key derived from
  // the admin entity key matches the key the option-source query actually uses.
  it('derives the same key the option-source query registers', () => {
    expect(optionSourceKey('customers')).toEqual(['all-customers'])
    expect(customersQuery().queryKey).toEqual(optionSourceKey('customers'))
    expect(usersQuery().queryKey).toEqual(optionSourceKey('users'))
    expect(ticketSystemsQuery().queryKey).toEqual(optionSourceKey('ticketsystems'))
  })
})
