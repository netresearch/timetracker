/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { createMemo, createResource, createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import { formatUserDate } from '../lib/dateFormat'
import {
  type ApiToken,
  type ApiTokenList,
  createApiToken,
  type CreatedApiToken,
  listApiTokens,
  revokeApiToken,
} from '../lib/apiTokens'
import { HelpPopover } from './HelpPopover'
import { m } from '../paraglide/messages.js'

/** A token is spent once revoked or past its expiry — both drop the Revoke action. */
function tokenState(token: ApiToken): 'revoked' | 'expired' | 'active' {
  if (token.revokedAt !== null) {
    return 'revoked'
  }
  if (token.expiresAt !== null && new Date(token.expiresAt).getTime() <= Date.now()) {
    return 'expired'
  }
  return 'active'
}

/** Format the date part with the user's date preference. Slicing the ISO string
 *  (rather than `new Date`) keeps a date-only value like a token expiry on its
 *  intended calendar day regardless of the viewer's timezone. */
function formatDate(iso: string | null): string {
  return iso === null ? '' : formatUserDate(iso.slice(0, 10))
}

/** Today as yyyy-mm-dd, for the expiry input's `min` (no past dates). */
function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

/** A scope preset: a fixed convenience selection a click drops into the picker,
 *  which the user can then still tweak checkbox by checkbox. Each `resolve`s
 *  against the server taxonomy so an unknown scope is silently dropped. */
interface ScopePreset {
  id: string
  label: () => string
  /** The preset's scope strings; `resources` feeds the derived read-only set. */
  resolve: (resources: readonly string[]) => string[]
}

/** Time-tracking core, reused as the base of the MCP-agent preset. */
const TIMETRACKING_SCOPES = ['entries:read', 'entries:write', 'projects:read', 'activities:read', 'customers:read']

const SCOPE_PRESETS: readonly ScopePreset[] = [
  {
    id: 'timetracking',
    label: () => m.settings_apitoken_preset_timetracking(),
    resolve: () => TIMETRACKING_SCOPES,
  },
  {
    id: 'mcp',
    label: () => m.settings_apitoken_preset_mcp(),
    resolve: () => [...TIMETRACKING_SCOPES, 'reporting:read', 'sync:read', 'sync:write'],
  },
  {
    id: 'jirasync',
    label: () => m.settings_apitoken_preset_jirasync(),
    resolve: () => ['sync:read', 'sync:write', 'entries:read'],
  },
  {
    id: 'readonly',
    label: () => m.settings_apitoken_preset_readonly(),
    resolve: (resources) => resources.map((resource) => `${resource}:read`),
  },
]

/** Every valid `resource:action` scope the server currently advertises. */
function validScopeSet(list: ApiTokenList | undefined): ReadonlySet<string> {
  const scopes = new Set<string>()
  for (const resource of list?.resources ?? []) {
    for (const action of list?.actions ?? []) {
      scopes.add(`${resource}:${action}`)
    }
  }
  return scopes
}

/** The preset's scopes narrowed to the ones the server actually accepts. */
function presetScopeSet(preset: ScopePreset, list: ApiTokenList | undefined): ReadonlySet<string> {
  const valid = validScopeSet(list)
  return new Set(preset.resolve(list?.resources ?? []).filter((scope) => valid.has(scope)))
}

function setsEqual(a: ReadonlySet<string>, b: ReadonlySet<string>): boolean {
  if (a.size !== b.size) {
    return false
  }
  for (const value of a) {
    if (!b.has(value)) {
      return false
    }
  }
  return true
}

/** Plain-language name for a scope resource. Falls back to the raw token so a
 *  server-added resource without a gloss still renders sensibly. */
const RESOURCE_GLOSS: Record<string, () => string> = {
  entries: () => m.settings_apitoken_scope_entries(),
  projects: () => m.settings_apitoken_scope_projects(),
  customers: () => m.settings_apitoken_scope_customers(),
  activities: () => m.settings_apitoken_scope_activities(),
  presets: () => m.settings_apitoken_scope_presets(),
  teams: () => m.settings_apitoken_scope_teams(),
  users: () => m.settings_apitoken_scope_users(),
  contracts: () => m.settings_apitoken_scope_contracts(),
  ticketsystems: () => m.settings_apitoken_scope_ticketsystems(),
  reporting: () => m.settings_apitoken_scope_reporting(),
  settings: () => m.settings_apitoken_scope_settings(),
  sync: () => m.settings_apitoken_scope_sync(),
}

function resourceGloss(resource: string): string {
  return RESOURCE_GLOSS[resource]?.() ?? resource
}

function actionGloss(action: string): string {
  if (action === 'write') {
    return m.settings_apitoken_scope_action_write()
  }
  if (action === 'read') {
    return m.settings_apitoken_scope_action_read()
  }
  return action
}

/** A hover title reading a `resource:action` scope in plain language,
 *  e.g. "Time entries — Create & edit". */
function scopeTitle(resource: string, action: string): string {
  return `${resourceGloss(resource)} — ${actionGloss(action)}`
}

/**
 * Settings → API tokens (ADR-021 Phase 3). Create a personal access
 * token (name, scopes, optional expiry), see the plaintext exactly once, list the
 * account's tokens, and revoke them. The scope picker is a resources × actions grid
 * plus a full-access (wildcard) shortcut — the taxonomy comes from the server so the
 * UI never hard-codes the scope list. The section heading is the surrounding
 * TokensSection's legend.
 */
export function ApiTokenControls(): JSX.Element {
  const [data, { refetch }] = createResource(listApiTokens)

  const [name, setName] = createSignal('')
  const [wildcard, setWildcard] = createSignal(false)
  const [scopes, setScopes] = createSignal<ReadonlySet<string>>(new Set())
  const [expiresAt, setExpiresAt] = createSignal('')
  const [busy, setBusy] = createSignal(false)
  const [error, setError] = createSignal('')
  const [created, setCreated] = createSignal<CreatedApiToken | null>(null)
  const [copied, setCopied] = createSignal(false)

  const wildcardScope = (): string => data()?.wildcard ?? '*'

  const effectiveScopes = (): string[] => (wildcard() ? [wildcardScope()] : [...scopes()])

  function toggleScope(scope: string, on: boolean): void {
    const next = new Set<string>(scopes())
    if (on) {
      next.add(scope)
    } else {
      next.delete(scope)
    }
    setScopes(next)
  }

  /** Drop a preset's (server-valid) scopes into the picker and leave wildcard off. */
  function applyPreset(preset: ScopePreset): void {
    setScopes(presetScopeSet(preset, data()))
    setWildcard(false)
  }

  /** The preset whose exact scope set the current selection matches, if any.
   *  Memoised so the per-button aria-pressed reads don't recompute it 4×. */
  const activePreset = createMemo((): string | null => {
    if (wildcard()) {
      return null
    }
    const current = scopes()
    if (current.size === 0) {
      return null
    }
    const list = data()
    for (const preset of SCOPE_PRESETS) {
      if (setsEqual(current, presetScopeSet(preset, list))) {
        return preset.id
      }
    }
    return null
  })

  function resetForm(): void {
    setName('')
    setWildcard(false)
    setScopes(new Set<string>())
    setExpiresAt('')
  }

  async function submit(event: Event): Promise<void> {
    event.preventDefault()
    setError('')

    const chosen = effectiveScopes()
    if (name().trim() === '' || chosen.length === 0) {
      setError(m.settings_apitoken_incomplete())
      return
    }

    setBusy(true)
    setCopied(false)
    try {
      const token = await createApiToken({
        name: name().trim(),
        scopes: chosen,
        expiresAt: expiresAt() || undefined,
      })
      setCreated(token)
      resetForm()
      await refetch()
    } catch (caught) {
      setError(apiErrorMessage(caught, m.settings_apitoken_error()))
    } finally {
      setBusy(false)
    }
  }

  async function revoke(id: number): Promise<void> {
    setBusy(true)
    setError('')
    try {
      await revokeApiToken(id)
      await refetch()
    } catch (caught) {
      setError(apiErrorMessage(caught, m.settings_apitoken_error()))
    } finally {
      setBusy(false)
    }
  }

  async function copyToken(): Promise<void> {
    const token = created()
    if (token === null) {
      return
    }
    try {
      await navigator.clipboard.writeText(token.token)
      setCopied(true)
      // Revert the label so a later copy of a different token reads "Copy" again.
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard denied (permissions / insecure context) — the value stays
      // visible in the field for a manual copy, so this is non-fatal.
    }
  }

  return (
    <div class="security-block">
      <p class="field-hint">{m.settings_apitoken_hint()}</p>

      {/* The one-time plaintext of the token just created. */}
      <Show when={created()}>
        {(token) => (
          <div class="apitoken-created" role="status">
            <p class="apitoken-created-title">{m.settings_apitoken_created_title()}</p>
            <p class="field-hint">{m.settings_apitoken_created_hint()}</p>
            <div class="apitoken-secret-row">
              <code class="apitoken-secret">{token().token}</code>
              <button type="button" class="ghost-button" onClick={() => void copyToken()}>
                {copied() ? m.settings_apitoken_copied() : m.settings_apitoken_copy()}
              </button>
            </div>
          </div>
        )}
      </Show>

      <Show
        when={(data()?.tokens.length ?? 0) > 0}
        fallback={<p class="field-hint">{m.settings_apitoken_none()}</p>}
      >
        <ul class="apitoken-list">
          <For each={data()?.tokens}>
            {(token) => {
              const state = tokenState(token)
              return (
                <li class={`apitoken-item${state === 'active' ? '' : ' is-inactive'}`}>
                  <div class="apitoken-item-main">
                    <span class="apitoken-name">{token.name}</span>
                    <Show when={state === 'revoked'}>
                      <span class="apitoken-badge">{m.settings_apitoken_revoked()}</span>
                    </Show>
                    <Show when={state === 'expired'}>
                      <span class="apitoken-badge">{m.settings_apitoken_expired()}</span>
                    </Show>
                  </div>
                  <div class="apitoken-scopes">
                    <For each={token.scopes}>{(scope) => <code class="apitoken-chip">{scope}</code>}</For>
                  </div>
                  <div class="apitoken-meta">
                    <span>
                      {m.settings_apitoken_last_used()}:{' '}
                      {token.lastUsedAt === null ? m.settings_apitoken_never_used() : formatDate(token.lastUsedAt)}
                    </span>
                    <span>
                      {m.settings_apitoken_expires()}:{' '}
                      {token.expiresAt === null ? m.settings_apitoken_no_expiry() : formatDate(token.expiresAt)}
                    </span>
                  </div>
                  <Show when={state === 'active'}>
                    <button type="button" class="ghost-button" disabled={busy()} onClick={() => void revoke(token.id)}>
                      {m.settings_apitoken_revoke()}
                    </button>
                  </Show>
                </li>
              )
            }}
          </For>
        </ul>
      </Show>

      <form class="apitoken-form" onSubmit={(event) => void submit(event)}>
        <label class="field">
          <span>{m.settings_apitoken_name_label()}</span>
          <input
            type="text"
            maxLength={100}
            placeholder={m.settings_apitoken_name_placeholder()}
            value={name()}
            onInput={(event) => setName(event.currentTarget.value)}
          />
        </label>

        {/* aria-labelledby names the group from the label span alone: the help
            trigger keeps its visual spot in the legend, but its "Help: …"
            aria-label stays out of the group's accessible name. */}
        <fieldset class="apitoken-scopes-picker" aria-labelledby="apitoken-scopes-label">
          <legend>
            <span id="apitoken-scopes-label">{m.settings_apitoken_scopes_label()}</span>
            <HelpPopover topic={m.settings_apitoken_scopes_label()}>{m.settings_help_token_scopes()}</HelpPopover>
          </legend>

          {/* Convenience presets: each click sets an exact scope set (narrowed to
              what the server advertises) and turns wildcard off; the user can then
              tweak individual checkboxes. aria-pressed marks the matching preset. */}
          <fieldset class="apitoken-presets">
            <legend class="field-hint apitoken-presets-label">{m.settings_apitoken_presets_label()}</legend>
            <For each={SCOPE_PRESETS}>
              {(preset) => (
                <button
                  type="button"
                  class="ghost-button apitoken-preset"
                  aria-pressed={activePreset() === preset.id}
                  disabled={data() === undefined}
                  onClick={() => applyPreset(preset)}
                >
                  {preset.label()}
                </button>
              )}
            </For>
          </fieldset>

          <label class="apitoken-wildcard">
            <input
              type="checkbox"
              checked={wildcard()}
              onChange={(event) => setWildcard(event.currentTarget.checked)}
            />
            <span>
              {m.settings_apitoken_scope_all()}
              <span class="field-hint"> — {m.settings_apitoken_scope_all_hint()}</span>
            </span>
          </label>

          <Show when={!wildcard()}>
            <table class="apitoken-scope-grid">
              <thead>
                <tr>
                  <th scope="col">{m.settings_apitoken_scope_resource()}</th>
                  <For each={data()?.actions}>{(action) => <th scope="col">{action}</th>}</For>
                </tr>
              </thead>
              <tbody>
                <For each={data()?.resources}>
                  {(resource) => (
                    <tr>
                      <th scope="row">
                        {resource}
                        <span class="field-hint apitoken-scope-gloss"> — {resourceGloss(resource)}</span>
                      </th>
                      <For each={data()?.actions}>
                        {(action) => {
                          const scope = `${resource}:${action}`
                          return (
                            <td>
                              <input
                                type="checkbox"
                                aria-label={scope}
                                title={scopeTitle(resource, action)}
                                checked={scopes().has(scope)}
                                onChange={(event) => toggleScope(scope, event.currentTarget.checked)}
                              />
                            </td>
                          )
                        }}
                      </For>
                    </tr>
                  )}
                </For>
              </tbody>
            </table>
          </Show>
        </fieldset>

        <label class="field">
          <span>{m.settings_apitoken_expiry_label()}</span>
          <input
            type="date"
            min={todayIso()}
            value={expiresAt()}
            onInput={(event) => setExpiresAt(event.currentTarget.value)}
          />
        </label>

        <div class="security-row">
          <button type="submit" class="primary-button" disabled={busy()}>
            {busy() ? m.app_saving() : m.settings_apitoken_create()}
          </button>
        </div>
      </form>

      <Show when={error()}>
        <span role="alert" class="form-status is-error">
          {error()}
        </span>
      </Show>
    </div>
  )
}
