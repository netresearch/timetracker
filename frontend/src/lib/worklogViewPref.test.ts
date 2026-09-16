import { beforeEach, describe, expect, it, vi } from 'vitest'

import { DEFAULT_WORKLOG_VIEW, getWorklogView, setWorklogView } from './worklogViewPref'

describe('worklogViewPref', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('returns the default when nothing is stored', () => {
    expect(getWorklogView()).toBe(DEFAULT_WORKLOG_VIEW)
  })

  it('round-trips a stored view', () => {
    setWorklogView('timeline')

    expect(getWorklogView()).toBe('timeline')
  })

  it('falls back when the stored value is not a known view', () => {
    // A value from a newer build, or hand-edited: rendering nothing would be worse
    // than ignoring it.
    localStorage.setItem('tt-worklog-view', 'kanban')

    expect(getWorklogView()).toBe(DEFAULT_WORKLOG_VIEW)
  })

  it('falls back when localStorage throws', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('private mode')
    })

    expect(getWorklogView('flat')).toBe('flat')
  })

  it('does not throw when the preference cannot be persisted', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('private mode')
    })

    expect(() => setWorklogView('flat')).not.toThrow()
  })
})
