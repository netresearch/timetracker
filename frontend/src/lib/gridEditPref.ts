// User preference for what happens to focus after committing an inline cell
// edit with Enter. Client-side only (like the theme) — a pure keyboard-interaction
// preference, so it lives in localStorage rather than the server-side settings.
// Tab/Shift+Tab always commit and move right/left regardless of this setting;
// only the Enter key is governed here.

export type EnterBehavior = 'stay' | 'down' | 'right'

const STORAGE_KEY = 'tt-grid-enter'
const DEFAULT: EnterBehavior = 'stay'

export function getEnterBehavior(): EnterBehavior {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    if (value === 'stay' || value === 'down' || value === 'right') {
      return value
    }
  } catch {
    // localStorage unavailable (private mode) — fall through to the default.
  }

  return DEFAULT
}

export function setEnterBehavior(value: EnterBehavior): void {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    // localStorage unavailable — the preference simply won't persist.
  }
}
