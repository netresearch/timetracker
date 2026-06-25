// User typography preferences (body font + text size) — ADR-014. Client-side
// only, like theme/density: applied before first paint by
// templates/partials/font-init.html.twig. This module shares those localStorage
// keys and re-applies the choice live when the Settings page changes it, so it
// takes effect without a reload. Headings keep the brand display face; only the
// body font and a --font-scale multiplier are user-governed.

export type FontFamily = 'default' | 'system' | 'dyslexic'
export type FontSize = 'normal' | 'large' | 'larger'

const FONT_KEY = 'timetracker-font'
const SCALE_KEY = 'timetracker-font-scale'

// Text-size option → the --font-scale multiplier (must match font-init's allow-list).
const SCALE: Record<FontSize, string> = { normal: '1', large: '1.15', larger: '1.3' }

export function getFontFamily(): FontFamily {
  try {
    const value = localStorage.getItem(FONT_KEY)
    if (value === 'system' || value === 'dyslexic') {
      return value
    }
  } catch {
    // localStorage unavailable (private mode) — fall through to the default.
  }

  return 'default'
}

export function getFontSize(): FontSize {
  try {
    const value = localStorage.getItem(SCALE_KEY)
    if (value === SCALE.large) {
      return 'large'
    }
    if (value === SCALE.larger) {
      return 'larger'
    }
  } catch {
    // localStorage unavailable — fall through to the default.
  }

  return 'normal'
}

function applyFamily(family: FontFamily): void {
  const root = document.documentElement
  if (family === 'default') {
    root.removeAttribute('data-font')
  } else {
    root.setAttribute('data-font', family)
  }
}

function applyScale(size: FontSize): void {
  const root = document.documentElement
  if (size === 'normal') {
    root.style.removeProperty('--font-scale')
  } else {
    root.style.setProperty('--font-scale', SCALE[size])
  }
}

export function setFontFamily(value: FontFamily): void {
  try {
    if (value === 'default') {
      localStorage.removeItem(FONT_KEY)
    } else {
      localStorage.setItem(FONT_KEY, value)
    }
  } catch {
    // localStorage unavailable — applied for this session only (below).
  }
  applyFamily(value)
}

export function setFontSize(value: FontSize): void {
  try {
    if (value === 'normal') {
      localStorage.removeItem(SCALE_KEY)
    } else {
      localStorage.setItem(SCALE_KEY, SCALE[value])
    }
  } catch {
    // localStorage unavailable — applied for this session only (below).
  }
  applyScale(value)
}

/** Re-apply both saved preferences to <html> (mirrors font-init on boot). */
export function applyFontPreferences(): void {
  applyFamily(getFontFamily())
  applyScale(getFontSize())
}
