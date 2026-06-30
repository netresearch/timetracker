import { useLocation, useNavigate } from '@solidjs/router'
import { createSignal, For, type JSX, onMount, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import { adminEntities } from '../admin/entities'
import { requestAdd } from '../admin/pendingAdd'
import { hasRole } from '../config'
import { PlusIcon } from '../lib/icons'
import { m } from '../paraglide/messages.js'

// Inner SVG markup per admin entity, so each sub-item carries a recognisable icon
// (consistent with the main nav). Keyed by entity key; a generic dot is the fallback.
const ADMIN_ICON: Record<string, string> = {
  customers: '<path d="M4 21V6l8-3 8 3v15"/><path d="M4 21h16M9 9h.01M9 13h.01M15 9h.01M15 13h.01"/>',
  projects: '<path d="M4 7a2 2 0 0 1 2-2h3l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>',
  users: '<circle cx="12" cy="8" r="3.2"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
  teams: '<circle cx="9" cy="9" r="2.8"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.5a3 3 0 0 1 0 5.4M20.5 19a5.5 5.5 0 0 0-3.5-5.1"/>',
  holidays: '<circle cx="12" cy="12" r="3.6"/><path d="M12 2.5v2M12 19.5v2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M18.4 5.6l1.4-1.4M4.2 19.8l1.4-1.4"/>',
  presets: '<path d="M6.5 3.5h11v17l-5.5-3.6-5.5 3.6z"/>',
  ticketsystems: '<path d="M3.5 10.5 10.5 3.5l10 10-7 7z"/><circle cx="8" cy="8" r="1.3"/>',
  activities: '<path d="M3 12h4l2.5 6 4-13 2.5 7H21"/>',
  contracts: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h5"/>',
  status: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 16h.01"/>',
}

function adminIco(key: string): JSX.Element {
  return (
    <svg
      class="sidebar-admin-ico"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      // eslint-disable-next-line solid/no-innerhtml
      innerHTML={ADMIN_ICON[key] ?? '<circle cx="12" cy="12" r="3"/>'}
    />
  )
}

/**
 * The admin entity switcher, rendered as nested items in the left sidebar (the
 * layout the user opted into via Settings). It portals into a slot the shared
 * Twig header exposes under the "Administration" link, so the entity list stays
 * single-sourced in SolidJS (adminEntities) rather than duplicated in Twig.
 *
 * Each entity row links to its admin route and carries a "+" that opens that
 * entity's add form — even from another page — via the pendingAdd hand-off
 * (AdminCrudShell consumes it on mount). The slot is CSS-hidden in the top-bar
 * layout, and the in-page tab bar is hidden in the sidebar layout, so the
 * switcher lives in exactly one place per layout.
 */
export function SidebarAdminMenu() {
  // Resolve the Twig-rendered slot after mount (not during setup) so we never race
  // the server-rendered header element. Admin-only; both are fixed for the session.
  const [slot, setSlot] = createSignal<HTMLElement | null>(null)
  onMount(() => setSlot(document.getElementById('sidebar-admin-slot')))
  const entities = adminEntities()
  const navigate = useNavigate()
  const location = useLocation()
  const activeKey = (): string | undefined => location.pathname.match(/\/admin\/([^/?]+)/)?.[1]
  // Only expand the entity list while you're in Administration — like a normal
  // disclosure. This keeps the sidebar short elsewhere (the always-expanded list
  // overflowed the column and pushed the main nav / worktime out of view).
  const onAdmin = (): boolean => /^\/admin(\/|$)/.test(location.pathname.replace(/^\/ui/, ''))

  return (
    <Show when={slot() !== null && hasRole('ROLE_ADMIN') && onAdmin()}>
      <Portal mount={slot()!}>
        <ul class="sidebar-admin-menu">
        <For each={entities}>
          {(entity) => (
            <li class="sidebar-admin-item" classList={{ 'is-active': entity.key === activeKey() }}>
              <a
                class="sidebar-admin-link"
                href={`/ui/admin/${entity.key}`}
                aria-current={entity.key === activeKey() ? 'page' : undefined}
                onClick={(event) => { event.preventDefault(); navigate(`/admin/${entity.key}`) }}
              >
                {adminIco(entity.key)}<span class="sidebar-admin-label">{entity.title()}</span>
              </a>
              <button
                type="button"
                class="sidebar-admin-add"
                aria-label={`${m.admin_add()}: ${entity.title()}`}
                title={`${m.admin_add()}: ${entity.title()}`}
                onClick={() => { requestAdd(entity.key); navigate(`/admin/${entity.key}`) }}
              >
                <PlusIcon />
              </button>
            </li>
          )}
        </For>
        {/* Read-only diagnostics sub-page — no add action. */}
        <li class="sidebar-admin-item" classList={{ 'is-active': activeKey() === 'status' }}>
          <a
            class="sidebar-admin-link"
            href="/ui/admin/status"
            aria-current={activeKey() === 'status' ? 'page' : undefined}
            onClick={(event) => { event.preventDefault(); navigate('/admin/status') }}
          >
            {adminIco('status')}<span class="sidebar-admin-label">{m.admin_status()}</span>
          </a>
        </li>
      </ul>
      </Portal>
    </Show>
  )
}
