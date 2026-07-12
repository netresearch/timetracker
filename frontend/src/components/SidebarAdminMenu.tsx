import { useLocation, useNavigate } from '@solidjs/router'
import { createSignal, For, type JSX, onMount, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import { adminEntities } from '../admin/entities'
import { requestAdd } from '../admin/pendingAdd'
import { hasRole } from '../config'
import { PlusIcon } from '../lib/icons'
import { m } from '../paraglide/messages.js'

// A recognisable icon per admin entity (consistent with the main nav). Each entry
// returns the inner SVG as JSX — no innerHTML. Keyed by entity key; generic dot
// is the fallback.
const ADMIN_ICON: Record<string, () => JSX.Element> = {
  customers: () => <><path d="M4 21V6l8-3 8 3v15" /><path d="M4 21h16M9 9h.01M9 13h.01M15 9h.01M15 13h.01" /></>,
  projects: () => <path d="M4 7a2 2 0 0 1 2-2h3l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z" />,
  users: () => <><circle cx="12" cy="8" r="3.2" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" /></>,
  teams: () => <><circle cx="9" cy="9" r="2.8" /><path d="M3.5 19a5.5 5.5 0 0 1 11 0" /><path d="M16 6.5a3 3 0 0 1 0 5.4M20.5 19a5.5 5.5 0 0 0-3.5-5.1" /></>,
  holidays: () => <><circle cx="12" cy="12" r="3.6" /><path d="M12 2.5v2M12 19.5v2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M18.4 5.6l1.4-1.4M4.2 19.8l1.4-1.4" /></>,
  presets: () => <path d="M6.5 3.5h11v17l-5.5-3.6-5.5 3.6z" />,
  ticketsystems: () => <><path d="M3.5 10.5 10.5 3.5l10 10-7 7z" /><circle cx="8" cy="8" r="1.3" /></>,
  activities: () => <path d="M3 12h4l2.5 6 4-13 2.5 7H21" />,
  contracts: () => <><path d="M7 3h7l5 5v13H7z" /><path d="M14 3v5h5M10 13h6M10 17h5" /></>,
  status: () => <><circle cx="12" cy="12" r="9" /><path d="M12 8v4.5M12 16h.01" /></>,
  'worklog-sync': () => <><path d="M4 12a8 8 0 0 1 14-5.3L21 9" /><path d="M21 4v5h-5" /><path d="M20 12a8 8 0 0 1-14 5.3L3 15" /><path d="M3 20v-5h5" /></>,
  'project-import': () => <><path d="M12 3v12" /><path d="M8 11l4 4 4-4" /><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></>,
  'personio-employee-match': () => <><path d="M16 11a4 4 0 1 0-8 0" /><circle cx="12" cy="7" r="0.5" /><path d="M4 21v-1a4 4 0 0 1 4-4h1" /><path d="M15 19l2 2 4-4" /></>,
}

function adminIco(key: string): JSX.Element {
  const inner = ADMIN_ICON[key] ?? (() => <circle cx="12" cy="12" r="3" />)

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
    >
      {inner()}
    </svg>
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
        {/* Worklog-sync area (run history, triggers, conflicts) — no add action. */}
        <li class="sidebar-admin-item" classList={{ 'is-active': activeKey() === 'worklog-sync' }}>
          <a
            class="sidebar-admin-link"
            href="/ui/admin/worklog-sync"
            aria-current={activeKey() === 'worklog-sync' ? 'page' : undefined}
            onClick={(event) => { event.preventDefault(); navigate('/admin/worklog-sync') }}
          >
            {adminIco('worklog-sync')}<span class="sidebar-admin-label">{m.worklogsync_admin_title()}</span>
          </a>
        </li>
        {/* Project-import review (ADR-026 P1): confirm derived customers — no add action. */}
        <li class="sidebar-admin-item" classList={{ 'is-active': activeKey() === 'project-import' }}>
          <a
            class="sidebar-admin-link"
            href="/ui/admin/project-import"
            aria-current={activeKey() === 'project-import' ? 'page' : undefined}
            onClick={(event) => { event.preventDefault(); navigate('/admin/project-import') }}
          >
            {adminIco('project-import')}<span class="sidebar-admin-label">{m.projectimport_admin_title()}</span>
          </a>
        </li>
        {/* Personio employee-match review (ADR-024 P3): confirm user→employee-id — no add action. */}
        <li class="sidebar-admin-item" classList={{ 'is-active': activeKey() === 'personio-employee-match' }}>
          <a
            class="sidebar-admin-link"
            href="/ui/admin/personio-employee-match"
            aria-current={activeKey() === 'personio-employee-match' ? 'page' : undefined}
            onClick={(event) => { event.preventDefault(); navigate('/admin/personio-employee-match') }}
          >
            {adminIco('personio-employee-match')}<span class="sidebar-admin-label">{m.personiomatch_admin_title()}</span>
          </a>
        </li>
      </ul>
      </Portal>
    </Show>
  )
}
