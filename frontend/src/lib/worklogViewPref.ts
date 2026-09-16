// User preference for the worklog view (grouped day sections / flat grid /
// read-only timeline). Client-side only, like the theme and the day range
// (see trackingDaysPref) — a pure view preference, so it lives in localStorage
// rather than the server-side settings, and survives remounts and logins.

const STORAGE_KEY = 'tt-worklog-view'

/**
 * `grouped` — day sections, shared context shown once per group (default).
 * `flat`    — one row per entry, every column on every row.
 *
 * A duration-proportional timeline was tried here and taken out again: the
 * design canvas puts that idea under Übersicht as a day view, not in the
 * worklog, and a stored 'timeline' now simply falls back to the default.
 */
export const WORKLOG_VIEWS = ['grouped', 'flat'] as const

export type WorklogView = (typeof WORKLOG_VIEWS)[number]

export const DEFAULT_WORKLOG_VIEW: WorklogView = 'grouped'

function isWorklogView(value: string): value is WorklogView {
  return (WORKLOG_VIEWS as readonly string[]).includes(value)
}

export function getWorklogView(fallback: WorklogView = DEFAULT_WORKLOG_VIEW): WorklogView {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    // A value written by a newer build (or hand-edited) must not brick the page:
    // anything outside the known set falls back instead of rendering nothing.
    if (raw !== null && isWorklogView(raw)) {
      return raw
    }
  } catch {
    // localStorage unavailable (private mode) — fall through to the default.
  }

  return fallback
}

export function setWorklogView(value: WorklogView): void {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    // localStorage unavailable — the preference simply won't persist.
  }
}
