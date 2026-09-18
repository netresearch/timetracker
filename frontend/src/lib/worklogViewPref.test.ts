/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
    setWorklogView('flat')

    expect(getWorklogView()).toBe('flat')
  })

  it('falls back when a view that no longer exists is stored', () => {
    // 'timeline' shipped for a while and may sit in a browser; it must not brick
    // the page now that the view is gone.
    localStorage.setItem('tt-worklog-view', 'timeline')

    expect(getWorklogView()).toBe(DEFAULT_WORKLOG_VIEW)
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
