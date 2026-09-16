import { beforeEach, describe, expect, it, vi } from 'vitest'

import { DEFAULT_WORKLOG_SORT, getWorklogSort, setWorklogSort } from './worklogSortPref'

describe('worklogSortPref', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('defaults to time order', () => {
    expect(getWorklogSort()).toBe(DEFAULT_WORKLOG_SORT)
  })

  it('round-trips a stored order', () => {
    setWorklogSort('context')

    expect(getWorklogSort()).toBe('context')
  })

  it('ignores a value it does not know', () => {
    localStorage.setItem('tt-worklog-sort', 'duration')

    expect(getWorklogSort()).toBe(DEFAULT_WORKLOG_SORT)
  })

  it('survives localStorage throwing', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('private mode')
    })

    expect(getWorklogSort('context')).toBe('context')
  })
})
