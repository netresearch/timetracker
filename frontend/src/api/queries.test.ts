import { describe, expect, it } from 'vitest'

import {
  activitiesQuery,
  customersQuery,
  groupQuery,
  hasInterpretationCriteria,
  type InterpretationFilters,
  optionSourceKey,
  ticketSystemsQuery,
  usersQuery,
} from './queries'

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

  it('does not count team/activity/dates alone', () => {
    expect(hasInterpretationCriteria({ ...base, team: 2, activity: 5, datestart: '2026-01-01' })).toBe(false)
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
