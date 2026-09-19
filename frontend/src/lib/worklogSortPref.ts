/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

// How the grouped worklog orders its blocks within a day. Client-side only, like
// the view and the day range (see worklogViewPref) — a view preference, so it
// lives in localStorage rather than the server-side settings.

const STORAGE_KEY = 'tt-worklog-sort'

/**
 * `time`    — newest first, the order the server sends (default).
 * `context` — by customer, project and activity, so a day's work on one thing
 *             stands together however scattered it was across the day.
 */
export const WORKLOG_SORTS = ['time', 'context'] as const

export type WorklogSort = (typeof WORKLOG_SORTS)[number]

export const DEFAULT_WORKLOG_SORT: WorklogSort = 'time'

function isWorklogSort(value: string): value is WorklogSort {
  return (WORKLOG_SORTS as readonly string[]).includes(value)
}

export function getWorklogSort(fallback: WorklogSort = DEFAULT_WORKLOG_SORT): WorklogSort {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw !== null && isWorklogSort(raw)) {
      return raw
    }
  } catch {
    // localStorage unavailable (private mode) — fall through to the default.
  }

  return fallback
}

export function setWorklogSort(value: WorklogSort): void {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    // localStorage unavailable — the preference simply won't persist.
  }
}
