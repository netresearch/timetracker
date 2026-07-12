import { useLocation, useNavigate, useParams } from '@solidjs/router'
import { createEffect, For, Match, Show, Switch } from 'solid-js'

import { adminEntities } from '../admin/entities'
import { useOptionSources } from '../admin/options'
import { AdminCrudShell } from '../components/AdminCrudShell'
import { activeNavLink } from '../header'
import { m } from '../paraglide/messages.js'
import AdminStatus from './AdminStatus'
import ProjectImport from './ProjectImport'
import WorklogSync from './WorklogSync'

// Non-CRUD sub-pages shown alongside the entity tabs: read-only diagnostics, the
// worklog-sync area, and the project-import review. Their keys are distinct from
// any entity key.
const STATUS_KEY = 'status'
const WORKLOG_SYNC_KEY = 'worklog-sync'
const PROJECT_IMPORT_KEY = 'project-import'

// Remembers the entity the Admin URL last selected. When a page-level modal
// (e.g. /ui/settings) opens over Admin, App.tsx re-renders Admin as the modal's
// background — but the live route is the modal's, so useParams() no longer
// carries the :entity segment. The background falls back to this so the (dimmed)
// backdrop keeps its tab instead of flashing to the first entity.
let lastAdminEntity: string | undefined

export default function Admin() {
  const entities = adminEntities()
  const params = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const { lookup } = useOptionSources()

  // True when Admin is the live route; false when it's a modal's background page.
  const onAdminRoute = () => location.pathname.replace(/^\/ui/, '').startsWith('/admin')
  // While Admin is the live route the URL segment is authoritative (and is
  // recorded); as a background it falls back to the last recorded segment.
  createEffect(() => {
    if (onAdminRoute()) {
      lastAdminEntity = params.entity
    }
  })
  const activeKey = () => {
    const key = params.entity ?? (onAdminRoute() ? undefined : lastAdminEntity)
    if (key === STATUS_KEY) {
      return STATUS_KEY
    }
    if (key === WORKLOG_SYNC_KEY) {
      return WORKLOG_SYNC_KEY
    }
    if (key === PROJECT_IMPORT_KEY) {
      return PROJECT_IMPORT_KEY
    }

    return entities.find((entity) => entity.key === key)?.key ?? entities[0]!.key
  }
  const active = () => entities.find((entity) => entity.key === activeKey()) ?? entities[0]!

  return (
    <section class="admin-page">

      {/* The entity switcher is part of the page's vertical keyboard chain
          (sub-nav ↔ search ↔ table): Left/Right/Home/End move between entities,
          ArrowDown hands focus down to the search field so the chain is
          bidirectional and never traps. Enter/Space (native button) switches. */}
      <nav
        class="admin-subnav"
        aria-label={m.admin_title()}
        onKeyDown={(event) => {
          const links = Array.from(event.currentTarget.querySelectorAll<HTMLButtonElement>('.admin-subnav-link'))
          const i = links.indexOf(document.activeElement as HTMLButtonElement)
          if (event.key === 'ArrowDown') {
            // Stop the event reaching the global handler (same delegated target),
            // which would otherwise see focus now on the search field and bounce
            // it straight on into the grid, skipping the search step.
            event.preventDefault()
            event.stopImmediatePropagation()
            document.querySelector<HTMLElement>('.admin-filter')?.focus()
          } else if (event.key === 'ArrowUp') {
            // Continue the chain upward to the active main navigation item
            // (activeNavLink skips links folded into the closed "More" menu, so
            // ArrowUp never dead-ends on a hidden element).
            event.preventDefault()
            activeNavLink()?.focus()
          } else if (event.key === 'ArrowRight') {
            event.preventDefault()
            links[Math.min(links.length - 1, i + 1)]?.focus()
          } else if (event.key === 'ArrowLeft') {
            event.preventDefault()
            links[Math.max(0, i - 1)]?.focus()
          } else if (event.key === 'Home') {
            event.preventDefault()
            links[0]?.focus()
          } else if (event.key === 'End') {
            event.preventDefault()
            links[links.length - 1]?.focus()
          }
        }}
      >
        <For each={entities}>
          {(entity) => (
            <button
              type="button"
              class="admin-subnav-link"
              classList={{ 'is-active': entity.key === activeKey() }}
              aria-current={entity.key === activeKey() ? 'page' : undefined}
              onClick={() => navigate(`/admin/${entity.key}`)}
            >
              {entity.title()}
            </button>
          )}
        </For>
        <button
          type="button"
          class="admin-subnav-link"
          classList={{ 'is-active': activeKey() === STATUS_KEY }}
          aria-current={activeKey() === STATUS_KEY ? 'page' : undefined}
          onClick={() => navigate(`/admin/${STATUS_KEY}`)}
        >
          {m.admin_status()}
        </button>
        <button
          type="button"
          class="admin-subnav-link"
          classList={{ 'is-active': activeKey() === WORKLOG_SYNC_KEY }}
          aria-current={activeKey() === WORKLOG_SYNC_KEY ? 'page' : undefined}
          onClick={() => navigate(`/admin/${WORKLOG_SYNC_KEY}`)}
        >
          {m.worklogsync_admin_title()}
        </button>
        <button
          type="button"
          class="admin-subnav-link"
          classList={{ 'is-active': activeKey() === PROJECT_IMPORT_KEY }}
          aria-current={activeKey() === PROJECT_IMPORT_KEY ? 'page' : undefined}
          onClick={() => navigate(`/admin/${PROJECT_IMPORT_KEY}`)}
        >
          {m.projectimport_admin_title()}
        </button>
      </nav>

      {/* keyed on the active entity so the shell remounts on switch — its sort,
          filter and any open edit form reset instead of bleeding across
          entities (and binding an open form to the wrong save endpoint). */}
      <Switch fallback={
        <Show when={activeKey()} keyed>
          <AdminCrudShell descriptor={active()} options={lookup} />
        </Show>
      }>
        <Match when={activeKey() === STATUS_KEY}>
          <AdminStatus />
        </Match>
        <Match when={activeKey() === WORKLOG_SYNC_KEY}>
          <WorklogSync />
        </Match>
        <Match when={activeKey() === PROJECT_IMPORT_KEY}>
          <ProjectImport />
        </Match>
      </Switch>
    </section>
  )
}
