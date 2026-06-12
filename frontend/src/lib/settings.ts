// Expected working hours per weekday. Like the previous stats UI this is a
// client-side setting (per browser) until a backend settings API exists;
// the storage shape is a map of JS weekday (1 = Monday … 5 = Friday) → hours.
const STORAGE_KEY = 'timetracker-hours-per-weekday'
const DEFAULT_HOURS_PER_DAY = 8

export function hoursPerWeekday(weekday: number): number {
  // window.localStorage explicitly: Node >= 22 exposes a global localStorage
  // that shadows the DOM one under test runners.
  const stored = window.localStorage.getItem(STORAGE_KEY)
  if (stored !== null) {
    try {
      const parsed: unknown = JSON.parse(stored)
      if (typeof parsed === 'object' && parsed !== null) {
        const hours = (parsed as Record<string, unknown>)[String(weekday)]
        if (typeof hours === 'number' && Number.isFinite(hours)) {
          return hours
        }
      }
    } catch {
      // Malformed storage falls back to the default below.
    }
  }

  return DEFAULT_HOURS_PER_DAY
}
