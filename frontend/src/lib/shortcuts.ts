import { m } from '../paraglide/messages.js'

export interface Shortcut {
  keys: string
  label: () => string
}

// App-wide shortcuts handled in the SolidJS shell (frontend/src/header.ts).
export const GLOBAL_SHORTCUTS: Shortcut[] = [
  { keys: 'Ctrl / ⌘ + K', label: () => m.help_sc_palette() },
  { keys: 'Alt + 1…7', label: () => m.help_sc_tabs() },
  { keys: 'Alt + A', label: () => m.help_sc_add() },
  { keys: '↑ ↓ ← →', label: () => m.help_sc_arrow_nav() },
  { keys: '/', label: () => m.help_sc_search() },
  { keys: '?', label: () => m.help_sc_help() },
]

// Data-table keyboard navigation (frontend/src/lib/gridNavigation.ts).
export const GRID_SHORTCUTS: Shortcut[] = [
  { keys: '↑ ↓ ← →', label: () => m.help_sc_cells() },
  { keys: 'Home / End', label: () => m.help_sc_row_edges() },
  { keys: 'Ctrl + Home / End', label: () => m.help_sc_grid_edges() },
  { keys: 'Page ↑ / ↓', label: () => m.help_sc_page() },
  { keys: 'Enter / F2', label: () => m.help_sc_enter_cell() },
  { keys: 'A–Z 0–9', label: () => m.help_sc_type_edit() },
  { keys: 'Enter / Tab / Esc', label: () => m.help_sc_commit_cell() },
  { keys: 'Tab / Shift + Tab', label: () => m.help_sc_cell_controls() },
  { keys: 'Esc', label: () => m.help_sc_leave_cell() },
  { keys: 'Ctrl + C', label: () => m.help_sc_copy_cell() },
  { keys: 'Ctrl + V', label: () => m.help_sc_paste_cell() },
  { keys: '↓', label: () => m.help_sc_search_table() },
  { keys: 'Esc', label: () => m.help_sc_search_clear() },
]

// Worklog grid accelerators, handled by Tracking.onGridShortcut (Alt+A via the
// header's add shortcut). The worklog deletes via Tab-to-trash and edits via
// Enter / F2, so there are no Alt+D/E accelerators.
export const TRACKING_SHORTCUTS: Shortcut[] = [
  { keys: 'Alt + A', label: () => m.help_sc_add() },
  { keys: 'Alt + C', label: () => m.help_sc_continue() },
  { keys: 'Alt + I', label: () => m.help_sc_info() },
  { keys: 'Alt + P', label: () => m.help_sc_prolong() },
  { keys: 'Alt + R', label: () => m.help_sc_refresh() },
  { keys: 'Alt + X', label: () => m.help_sc_export() },
]
