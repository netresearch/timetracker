import { For } from 'solid-js'

import { appConfig } from '../config'
import { m } from '../paraglide/messages.js'

interface Shortcut {
  keys: string
  label: () => string
}

// App-wide shortcuts handled in the SolidJS shell (frontend/src/header.ts).
const GLOBAL_SHORTCUTS: Shortcut[] = [
  { keys: 'Alt + 1…7', label: () => m.help_sc_tabs() },
  { keys: 'Alt + A', label: () => m.help_sc_add() },
  { keys: '/', label: () => m.help_sc_search() },
  { keys: '?', label: () => m.help_sc_help() },
]

// Data-table keyboard navigation (frontend/src/lib/gridNavigation.ts).
const GRID_SHORTCUTS: Shortcut[] = [
  { keys: '↑ ↓ ← →', label: () => m.help_sc_cells() },
  { keys: 'Home / End', label: () => m.help_sc_row_edges() },
  { keys: 'Ctrl + Home / End', label: () => m.help_sc_grid_edges() },
  { keys: 'Page ↑ / ↓', label: () => m.help_sc_page() },
  { keys: 'Enter / F2', label: () => m.help_sc_enter_cell() },
  { keys: 'Tab / Shift + Tab', label: () => m.help_sc_cell_controls() },
  { keys: 'Esc', label: () => m.help_sc_leave_cell() },
  { keys: '↓ / Esc', label: () => m.help_sc_search_table() },
]

// Classic time-tracking grid (still the ExtJS shell at /; KeyMap in main.js).
const TRACKING_SHORTCUTS: Shortcut[] = [
  { keys: 'Alt + A', label: () => m.help_sc_add() },
  { keys: 'Alt + C', label: () => m.help_sc_continue() },
  { keys: 'Alt + D', label: () => m.help_sc_delete() },
  { keys: 'Alt + E', label: () => m.help_sc_edit() },
  { keys: 'Alt + I', label: () => m.help_sc_info() },
  { keys: 'Alt + P', label: () => m.help_sc_prolong() },
  { keys: 'Alt + R', label: () => m.help_sc_refresh() },
  { keys: 'Alt + X', label: () => m.help_sc_export() },
]

function ShortcutTable(props: { caption: string; rows: Shortcut[] }) {
  return (
    <table class="shortcut-table">
      <caption>{props.caption}</caption>
      <tbody>
        <For each={props.rows}>
          {(row) => (
            <tr>
              <th scope="row"><kbd>{row.keys}</kbd></th>
              <td>{row.label()}</td>
            </tr>
          )}
        </For>
      </tbody>
    </table>
  )
}

export default function Help() {
  const config = appConfig()

  return (
    <section class="help-page">
      <h2 class="visually-hidden">{m.help_title()}</h2>

      <section class="help-section">
        <h3>{m.help_usage()}</h3>
        <ul>
          <li>{m.help_usage_add()}</li>
          <li>{m.help_usage_edit()}</li>
          <li>{m.help_usage_delete()}</li>
          <li>{m.help_usage_focus()}</li>
        </ul>
      </section>

      <section class="help-section">
        <h3>{m.help_shortcuts()}</h3>
        <div class="shortcut-tables">
          <ShortcutTable caption={m.help_shortcuts_global()} rows={GLOBAL_SHORTCUTS} />
          <ShortcutTable caption={m.help_shortcuts_grid()} rows={GRID_SHORTCUTS} />
          <ShortcutTable caption={m.help_shortcuts_tracking()} rows={TRACKING_SHORTCUTS} />
        </div>
      </section>

      <section class="help-section">
        <h3>{m.help_links()}</h3>
        <ul>
          <li>
            <a href="https://github.com/netresearch/timetracker" target="_blank" rel="noopener noreferrer">
              {m.help_link_project()}
            </a>
          </li>
          <li>
            <a href={config.legacyUrl + 'api.yml'} target="_blank" rel="noopener noreferrer">
              {m.help_link_api()}
            </a>
          </li>
        </ul>
      </section>
    </section>
  )
}
