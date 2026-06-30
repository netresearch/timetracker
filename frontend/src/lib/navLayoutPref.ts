// Navigation-layout preference (top bar vs left sidebar). Client-side only, like
// theme/density/font: applied before first paint by
// templates/partials/nav-layout-init.html.twig. This module shares that
// localStorage key and re-applies the choice live when the Settings page changes
// it, so it takes effect without a reload. The layout itself is pure CSS keyed on
// data-nav-layout (see app.css); switching only flips the attribute and asks the
// shared header script to re-run its priority-overflow pass.

export type NavLayout = 'top' | 'side'

const KEY = 'timetracker-nav-layout'

export function getNavLayout(): NavLayout {
  try {
    if (localStorage.getItem(KEY) === 'side') {
      return 'side'
    }
  } catch {
    // localStorage unavailable (private mode) — fall through to the default.
  }

  return 'top'
}

function apply(layout: NavLayout): void {
  const root = document.documentElement
  if (layout === 'side') {
    root.setAttribute('data-nav-layout', 'side')
  } else {
    root.removeAttribute('data-nav-layout')
  }
}

export function setNavLayout(value: NavLayout): void {
  try {
    if (value === 'side') {
      localStorage.setItem(KEY, 'side')
    } else {
      localStorage.removeItem(KEY)
    }
  } catch {
    // localStorage unavailable — applied for this session only (below).
  }
  apply(value)
  // The shared header script (header-behavior.html.twig) folds nav items into a
  // "More" menu by horizontal width; the row↔column switch invalidates that pass,
  // so ask it to re-measure and restore items to their natural slots.
  window.dispatchEvent(new CustomEvent('tt:layout-change'))
}

/** Re-apply the saved preference to <html> (mirrors nav-layout-init on boot). */
export function applyNavLayout(): void {
  apply(getNavLayout())
}
