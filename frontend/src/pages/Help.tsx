import { ShortcutTable } from '../components/ShortcutTable'
import { GLOBAL_SHORTCUTS, GRID_SHORTCUTS, TRACKING_SHORTCUTS } from '../lib/shortcuts'
import { m } from '../paraglide/messages.js'

export default function Help() {
  return (
    <section class="help-page">

      <section class="help-section">
        <h2>{m.help_usage()}</h2>
        <ul>
          <li>{m.help_usage_add()}</li>
          <li>{m.help_usage_edit()}</li>
          <li>{m.help_usage_delete()}</li>
          <li>{m.help_usage_focus()}</li>
          <li>{m.help_usage_autosave()}</li>
        </ul>
      </section>

      <section class="help-section">
        <h2>{m.help_shortcuts()}</h2>
        <p class="help-intro">{m.help_shortcuts_intro()}</p>
        <div class="shortcut-tables">
          <ShortcutTable caption={m.help_shortcuts_global()} rows={GLOBAL_SHORTCUTS} />
          <ShortcutTable caption={m.help_shortcuts_grid()} rows={GRID_SHORTCUTS} />
          <ShortcutTable caption={m.help_shortcuts_tracking()} rows={TRACKING_SHORTCUTS} />
        </div>
      </section>

      <section class="help-section">
        <h2>{m.help_links()}</h2>
        <ul>
          <li>
            <a href="https://github.com/netresearch/timetracker" target="_blank" rel="noopener noreferrer">
              {m.help_link_project()}
            </a>
          </li>
          <li>
            <a href="/api.yml" target="_blank" rel="noopener noreferrer">
              {m.help_link_api()}
            </a>
          </li>
        </ul>
      </section>
    </section>
  )
}
