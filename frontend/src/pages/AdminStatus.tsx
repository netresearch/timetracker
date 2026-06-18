import { createResource, For, Show } from 'solid-js'

import { getJson } from '../api/client'
import { m } from '../paraglide/messages.js'

interface StatusData {
  app: { title: string | null; env: string; debug: boolean; locale: string | null; version: string | null }
  php: { version: string; extensions: string[] }
  symfony: { kernel: string }
  packages: Record<string, string | null>
  database: { driver: string | null; platform: string | null; serverVersion: string | null; host: string | null; port: string | null; name: string | null }
  config: Record<string, unknown>
}

function fmt(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '—'
  }
  if (typeof value === 'boolean') {
    return value ? m.app_yes() : m.app_no()
  }
  if (Array.isArray(value)) {
    return value.length > 0 ? value.join(', ') : '—'
  }

  return String(value)
}

function StatusGroup(props: { title: string; rows: Record<string, unknown> }) {
  return (
    <section class="status-group">
      <h2 class="status-group-title">{props.title}</h2>
      <dl class="status-list">
        <For each={Object.entries(props.rows)}>
          {([key, value]) => (
            <>
              <dt>{key}</dt>
              <dd>{fmt(value)}</dd>
            </>
          )}
        </For>
      </dl>
    </section>
  )
}

/** Read-only diagnostics (versions, DB, config) for admins and bug reports. */
export default function AdminStatus() {
  const [data] = createResource(() => getJson<StatusData>('/admin/status'))

  return (
    <section class="status-page" aria-busy={data.loading ? 'true' : undefined}>
      <Show when={data.error}>
        <p class="form-status is-error" role="alert">{m.status_load_error()}</p>
      </Show>
      {/* `!data.error &&` short-circuits so data() isn't read in the error state
          (a Solid resource rethrows the error when its value is accessed). */}
      <Show when={!data.error && data()}>
        {(status) => (
          <>
            <StatusGroup title={m.status_app()} rows={status().app} />
            <StatusGroup title={m.status_php()} rows={{ version: status().php.version, extensions: status().php.extensions }} />
            <StatusGroup title={m.status_symfony()} rows={status().symfony} />
            <StatusGroup title={m.status_database()} rows={status().database} />
            <StatusGroup title={m.status_packages()} rows={status().packages} />
            <StatusGroup title={m.status_config()} rows={status().config} />
          </>
        )}
      </Show>
    </section>
  )
}
