import { createSignal, For, Show } from 'solid-js'

import { adminEntities } from '../admin/entities'
import { useOptionSources } from '../admin/options'
import { AdminCrudShell } from '../components/AdminCrudShell'
import { activeNavLink } from '../header'
import { m } from '../paraglide/messages.js'

export default function Admin() {
  const entities = adminEntities()
  const [activeKey, setActiveKey] = createSignal(entities[0]!.key)
  const { lookup } = useOptionSources()

  const active = () => entities.find((entity) => entity.key === activeKey()) ?? entities[0]!

  return (
    <section class="admin-page">
      <h2 class="visually-hidden">{m.admin_title()}</h2>

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
              onClick={() => setActiveKey(entity.key)}
            >
              {entity.title()}
            </button>
          )}
        </For>
      </nav>

      {/* keyed on the active entity so the shell remounts on switch — its sort,
          filter and any open edit form reset instead of bleeding across
          entities (and binding an open form to the wrong save endpoint). */}
      <Show when={activeKey()} keyed>
        <AdminCrudShell descriptor={active()} options={lookup} />
      </Show>
    </section>
  )
}
