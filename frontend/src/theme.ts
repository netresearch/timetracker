import { createSignal } from 'solid-js'

export type ThemePreference = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'timetracker-theme'

function readStoredPreference(): ThemePreference {
  // window.localStorage explicitly: Node >= 22 exposes a global localStorage
  // that shadows the DOM one under test runners.
  const stored = window.localStorage.getItem(STORAGE_KEY)

  return stored === 'light' || stored === 'dark' ? stored : 'system'
}

function applyPreference(preference: ThemePreference): void {
  if (preference === 'system') {
    delete document.documentElement.dataset.theme
  } else {
    document.documentElement.dataset.theme = preference
  }
}

const initialPreference = readStoredPreference()
const [themePreference, setSignal] = createSignal<ThemePreference>(initialPreference)

applyPreference(initialPreference)

export { themePreference }

export function setThemePreference(preference: ThemePreference): void {
  setSignal(preference)
  applyPreference(preference)

  if (preference === 'system') {
    window.localStorage.removeItem(STORAGE_KEY)
  } else {
    window.localStorage.setItem(STORAGE_KEY, preference)
  }
}
