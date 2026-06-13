import { createSignal, For } from 'solid-js'

import { adminEntities } from '../admin/entities'
import { useOptionSources } from '../admin/options'
import { AdminCrudShell } from '../components/AdminCrudShell'
import { ThemeToggle } from '../components/ThemeToggle'
import { m } from '../paraglide/messages.js'

export default function Admin() {
  const entities = adminEntities()
  const [activeKey, setActiveKey] = createSignal(entities[0]!.key)
  const { lookup } = useOptionSources()

  const active = () => entities.find((entity) => entity.key === activeKey()) ?? entities[0]!

  return (
    <section class="admin-page">
      <div class="month-toolbar">
        <h2>{m.admin_title()}</h2>
        <ThemeToggle />
      </div>

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

      <AdminCrudShell descriptor={active()} options={lookup} />
    </section>
  )
}
