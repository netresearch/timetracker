import { createSignal, For, Show } from 'solid-js'

import { adminEntities } from '../admin/entities'
import { useOptionSources } from '../admin/options'
import { AdminCrudShell } from '../components/AdminCrudShell'
import { m } from '../paraglide/messages.js'

export default function Admin() {
  const entities = adminEntities()
  const [activeKey, setActiveKey] = createSignal(entities[0]!.key)
  const { lookup } = useOptionSources()

  const active = () => entities.find((entity) => entity.key === activeKey()) ?? entities[0]!

  return (
    <section class="admin-page">
      <h2 class="visually-hidden">{m.admin_title()}</h2>

      <nav class="admin-subnav" aria-label={m.admin_title()}>
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
