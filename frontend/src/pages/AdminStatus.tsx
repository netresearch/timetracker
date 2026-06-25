import { createResource, For, Show, Switch, Match } from 'solid-js'

import { getJson } from '../api/client'
import { m } from '../paraglide/messages.js'

interface BuildInfo {
  revision: string | null
  ref: string | null
  date: string | null
  repositoryUrl: string
  commitUrl: string | null
  refUrl: string | null
  releasesUrl: string
}

interface StatusData {
  app: { title: string | null; env: string; debug: boolean; locale: string | null; version: string | null }
  build: BuildInfo
  php: { version: string; extensions: string[] }
  symfony: { kernel: string }
  packages: Record<string, string | null>
  database: { driver: string | null; platform: string | null; serverVersion: string | null; host: string | null; port: string | null; name: string | null }
  config: Record<string, unknown>
}

interface UpdateStatus {
  state: 'current' | 'behind'
  behind: number | null
  compareUrl: string | null
}

// Translations for the fixed structural keys (app/php/symfony/database groups).
// The packages + config groups carry raw identifiers (composer names, config
// keys), so those fall back to the key itself.
const FIELD_LABELS: Record<string, () => string> = {
  title: () => m.status_field_title(),
  env: () => m.status_field_env(),
  debug: () => m.status_field_debug(),
  locale: () => m.status_field_locale(),
  version: () => m.status_field_version(),
  extensions: () => m.status_field_extensions(),
  kernel: () => m.status_field_kernel(),
  driver: () => m.status_field_driver(),
  platform: () => m.status_field_platform(),
  serverVersion: () => m.status_field_serverVersion(),
  host: () => m.status_field_host(),
  port: () => m.status_field_port(),
  name: () => m.status_field_name(),
}
const fieldLabel = (key: string): string => FIELD_LABELS[key]?.() ?? key

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
  if (typeof value === 'object') {
    return JSON.stringify(value)
  }

  return String(value)
}

function formatDate(iso: string | null): string {
  if (!iso) {
    return '—'
  }
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}

function StatusGroup(props: Readonly<{ title: string; rows: Record<string, unknown> }>) {
  return (
    <section class="status-group">
      <h2 class="status-group-title">{props.title}</h2>
      <dl class="status-list">
        <For each={Object.entries(props.rows)}>
          {([key, value]) => (
            <>
              <dt>{fieldLabel(key)}</dt>
              <dd>{fmt(value)}</dd>
            </>
          )}
        </For>
      </dl>
    </section>
  )
}

// Compare the running image's commit against the latest on the default branch,
// using GitHub's public REST API from the admin's browser (the repo is public,
// so no token is needed; unauthenticated calls are rate-limited but fine for an
// occasional status view). Throws on transport/rate-limit errors → resource.error.
async function fetchUpdateStatus(build: BuildInfo): Promise<UpdateStatus> {
  const api = 'https://api.github.com/repos/netresearch/timetracker'
  const accept = { Accept: 'application/vnd.github+json' }

  const head = await fetch(`${api}/commits/main`, { headers: accept })
  if (!head.ok) {
    throw new Error(`github ${head.status}`)
  }
  const latest = String((await head.json()).sha)
  // Both are normally full 40-char SHAs, but compare prefix-tolerantly so a
  // short revision (or a short API sha) still matches rather than false-flagging.
  const sameCommit =
    Boolean(build.revision) && (latest === build.revision || latest.startsWith(build.revision!) || build.revision!.startsWith(latest))
  if (!build.revision || sameCommit) {
    return { state: 'current', behind: null, compareUrl: null }
  }

  let behind: number | null = null
  try {
    const compare = await fetch(`${api}/compare/${build.revision}...${latest}`, { headers: accept })
    if (compare.ok) {
      behind = Number((await compare.json()).ahead_by) || null
    }
  } catch {
    // The commit count is a nice-to-have; the "behind" verdict already stands.
  }

  return { state: 'behind', behind, compareUrl: `${build.repositoryUrl}/compare/${build.revision}...${latest}` }
}

function BuildSection(props: Readonly<{ build: BuildInfo }>) {
  // Only probe GitHub once we know our own commit; a local build (no revision)
  // has nothing to compare and shows "unknown" (a false source skips the fetch).
  const [update] = createResource(
    () => (props.build.revision ? props.build : false),
    (build) => fetchUpdateStatus(build),
  )

  return (
    <section class="status-group">
      <h2 class="status-group-title">{m.status_build()}</h2>
      <dl class="status-list">
        <dt>{m.status_field_revision()}</dt>
        <dd>
          <Show when={props.build.commitUrl} fallback="—">
            {(url) => (
              <a href={url()} target="_blank" rel="noreferrer"><code>{props.build.revision?.slice(0, 8)}</code></a>
            )}
          </Show>
        </dd>

        <dt>{m.status_field_ref()}</dt>
        <dd>
          <Show when={props.build.refUrl} fallback={props.build.ref ?? '—'}>
            {(url) => <a href={url()} target="_blank" rel="noreferrer">{props.build.ref}</a>}
          </Show>
        </dd>

        <dt>{m.status_field_date()}</dt>
        <dd>{formatDate(props.build.date)}</dd>

        <dt>{m.status_field_links()}</dt>
        <dd class="status-links">
          <a href={props.build.repositoryUrl} target="_blank" rel="noreferrer">{m.status_build_repository()}</a>
          {' · '}
          <a href={props.build.releasesUrl} target="_blank" rel="noreferrer">{m.status_build_releases()}</a>
        </dd>

        <dt>{m.status_field_update()}</dt>
        <dd aria-live="polite">
          <Switch fallback={m.status_update_unknown()}>
            <Match when={!props.build.revision}>{m.status_update_unknown()}</Match>
            <Match when={update.loading}>{m.status_update_checking()}</Match>
            <Match when={update.error}>{m.status_update_error()}</Match>
            <Match when={update()?.state === 'current'}>
              <span class="status-update is-current">{m.status_update_current()}</span>
            </Match>
            <Match when={update()?.state === 'behind'}>
              <span class="status-update is-behind">
                {update()?.behind ? m.status_update_available_count({ count: String(update()?.behind) }) : m.status_update_available()}
              </span>
              <Show when={update()?.compareUrl}>
                {(url) => <> · <a href={url()} target="_blank" rel="noreferrer">{m.status_update_compare()}</a></>}
              </Show>
            </Match>
          </Switch>
        </dd>
      </dl>
    </section>
  )
}

/** Read-only diagnostics (versions, build, DB, config) for admins and bug reports. */
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
            <BuildSection build={status().build} />
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
