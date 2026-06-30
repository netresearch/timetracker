import { useLocation, useNavigate } from '@solidjs/router'
import { createSignal, For, onMount, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import { adminEntities } from '../admin/entities'
import { requestAdd } from '../admin/pendingAdd'
import { hasRole } from '../config'
import { PlusIcon } from '../lib/icons'
import { m } from '../paraglide/messages.js'

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
                {entity.title()}
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
            {m.admin_status()}
          </a>
        </li>
      </ul>
      </Portal>
    </Show>
  )
}
