import { ShortcutTable } from '../components/ShortcutTable'
import { ContinueIcon, DiskIcon, InfoIcon, ProlongIcon, ResetIcon, TrashIcon } from '../lib/icons'
import { GLOBAL_SHORTCUTS, GRID_SHORTCUTS, TRACKING_SHORTCUTS } from '../lib/shortcuts'
import { m } from '../paraglide/messages.js'

export default function Help() {
  return (
    <section class="help-page">
      <p class="help-lead">{m.help_intro()}</p>

      <section class="help-section">
        <h2>{m.help_pages()}</h2>
        <p class="help-intro">{m.help_pages_intro()}</p>
        <dl class="help-legend">
          <div class="help-legend-row">
            <dt>{m.tracking_title()}</dt>
            <dd>{m.help_page_tracking_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.month_title()}</dt>
            <dd>{m.help_page_month_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.auswertung_title()}</dt>
            <dd>{m.help_page_auswertung_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.billing_title()}</dt>
            <dd>{m.help_page_billing_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.admin_title()}</dt>
            <dd>{m.help_page_admin_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.settings_title()}</dt>
            <dd>{m.help_page_settings_desc()}</dd>
          </div>
        </dl>
      </section>

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
        <h2>{m.help_legend()}</h2>
        <p class="help-intro">{m.help_legend_intro()}</p>

        <h3 class="help-subheading">{m.help_legend_colours()}</h3>
        <dl class="help-legend">
          <div class="help-legend-row">
            <dt><span class="tracking-legend-item is-daybreak">{m.tracking_class_daybreak()}</span></dt>
            <dd>{m.help_legend_daybreak_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-item is-pause">{m.tracking_class_pause()}</span></dt>
            <dd>{m.help_legend_break_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-item is-overlap">{m.tracking_class_overlap()}</span></dt>
            <dd>{m.help_legend_overlap_desc()}</dd>
          </div>
        </dl>

        <h3 class="help-subheading">{m.help_legend_icons()}</h3>
        <dl class="help-legend">
          <div class="help-legend-row">
            <dt><span class="tracking-legend-icon"><ContinueIcon /> {m.tracking_continue()}</span></dt>
            <dd>{m.help_icon_continue_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-icon"><ProlongIcon /> {m.tracking_prolong()}</span></dt>
            <dd>{m.help_icon_prolong_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-icon"><InfoIcon /> {m.tracking_info()}</span></dt>
            <dd>{m.help_icon_info_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-icon"><TrashIcon /> {m.admin_delete()}</span></dt>
            <dd>{m.help_icon_delete_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-icon"><DiskIcon /> {m.app_save()}</span></dt>
            <dd>{m.help_icon_save_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt><span class="tracking-legend-icon"><ResetIcon /> {m.tracking_reset()}</span></dt>
            <dd>{m.help_icon_discard_desc()}</dd>
          </div>
        </dl>
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
        <h2>{m.help_controls()}</h2>
        <p class="help-intro">{m.help_controls_intro()}</p>
        <dl class="help-legend">
          <div class="help-legend-row">
            <dt>{m.help_control_theme()}</dt>
            <dd>{m.help_control_theme_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.help_control_density()}</dt>
            <dd>{m.help_control_density_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.help_control_layout()}</dt>
            <dd>{m.help_control_layout_desc()}</dd>
          </div>
          <div class="help-legend-row">
            <dt>{m.help_control_logout()}</dt>
            <dd>{m.help_control_logout_desc()}</dd>
          </div>
        </dl>
      </section>

      <section class="help-section">
        <h2>{m.help_links()}</h2>
        <ul>
          <li>
            <a href="https://github.com/netresearch/timetracker/blob/main/docs/user-guide.md" target="_blank" rel="noopener noreferrer">
              {m.help_link_user_guide()}
            </a>
          </li>
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
