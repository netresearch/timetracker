// User preference for the worklog day-range filter. Client-side only (like the
// theme and the Enter-commit behaviour) — a pure view preference, so it lives in
// localStorage rather than the server-side settings. Persisting it keeps the
// chosen range across remounts/logins instead of snapping back to the default.

const STORAGE_KEY = 'tt-tracking-days'

export function getTrackingDays(fallback: number): number {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw !== null) {
      const value = Number(raw)
      // The range is freetext now (any whole number ≥ 1), so validate the shape
      // rather than membership in a fixed preset list. The caller caps the upper end.
      if (Number.isInteger(value) && value >= 1) {
        return value
      }
    }
  } catch {
    // localStorage unavailable (private mode) — fall through to the default.
  }

  return fallback
}

export function setTrackingDays(value: number): void {
  try {
    localStorage.setItem(STORAGE_KEY, String(value))
  } catch {
    // localStorage unavailable — the preference simply won't persist.
  }
}
