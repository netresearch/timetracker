/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { createEffect, createMemo, createResource, createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import { DateField } from './DateField'
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

  /** How many of an action column's checkboxes are ticked — drives the header
   *  select-all's checked / indeterminate (three) state. */
  function columnState(action: string): { checked: boolean; indeterminate: boolean } {
    const resources = data()?.resources ?? []
    if (resources.length === 0) {
      return { checked: false, indeterminate: false }
    }
    let checked = 0
    for (const resource of resources) {
      if (scopes().has(`${resource}:${action}`)) {
        checked += 1
      }
    }
    return { checked: checked === resources.length, indeterminate: checked > 0 && checked < resources.length }
  }

  /** Header select-all: tick or clear every resource's scope for one action column. */
  function toggleColumn(action: string, on: boolean): void {
    const next = new Set<string>(scopes())
    for (const resource of data()?.resources ?? []) {
      const scope = `${resource}:${action}`
      if (on) {
        next.add(scope)
      } else {
        next.delete(scope)
      }
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

    // A past expiry would create a token that is already spent; the enhanced
    // date field (unlike the old native <input min>) doesn't block it, so guard
    // here (ISO strings compare lexicographically).
    const expiry = expiresAt()
    if (expiry !== '' && expiry < new Date().toISOString().slice(0, 10)) {
      setError(m.settings_apitoken_expiry_past())
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

      <h3 class="apitoken-subheading">{m.settings_apitoken_create_heading()}</h3>

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

          {/* Access mode: "By resource" reveals the scope grid; "Full access"
              swaps to the wildcard ('*') and a caution box. The underlying state
              stays the same wildcard boolean the rest of the form reads. */}
          <fieldset class="apitoken-mode" aria-labelledby="apitoken-mode-label">
            <legend id="apitoken-mode-label" class="apitoken-mode-legend">{m.settings_apitoken_mode_label()}</legend>
            <div class="apitoken-mode-options">
              <label class="apitoken-mode-option">
                <input type="radio" name="apitoken-mode" checked={!wildcard()} onChange={() => setWildcard(false)} />
                <span>{m.settings_apitoken_mode_scoped()}</span>
              </label>
              <label class="apitoken-mode-option">
                <input type="radio" name="apitoken-mode" checked={wildcard()} onChange={() => setWildcard(true)} />
                <span>{m.settings_apitoken_scope_all()}</span>
              </label>
            </div>
          </fieldset>

          <Show when={wildcard()}>
            <p class="apitoken-scope-warning" role="note">
              <strong>{m.settings_apitoken_scope_all()}</strong> — {m.settings_apitoken_scope_all_warning()}
            </p>
          </Show>

          <Show when={!wildcard()}>
            {/* --apitoken-actions drives the grid's column count so the header
                select-all boxes stay aligned above their column of checkboxes. */}
            <div
              class="apitoken-scope-grid"
              style={{ '--apitoken-actions': String(data()?.actions?.length ?? 2) }}
            >
              <div class="apitoken-scope-head">
                <span class="apitoken-scope-head-resource">{m.settings_apitoken_scope_resource()}</span>
                <For each={data()?.actions}>
                  {(action) => {
                    let ref: HTMLInputElement | undefined
                    // indeterminate is a DOM property, not an attribute — set it
                    // from the per-column count whenever the selection changes.
                    createEffect(() => {
                      if (ref !== undefined) {
                        ref.indeterminate = columnState(action).indeterminate
                      }
                    })
                    return (
                      <label class="apitoken-scope-col-head">
                        <input
                          ref={(el) => {
                            ref = el
                          }}
                          type="checkbox"
                          aria-label={m.settings_apitoken_select_all({ action })}
                          checked={columnState(action).checked}
                          onChange={(event) => toggleColumn(action, event.currentTarget.checked)}
                        />
                        <span aria-hidden="true">{action}</span>
                      </label>
                    )
                  }}
                </For>
              </div>
              <For each={data()?.resources}>
                {(resource) => (
                  <div class="apitoken-scope-row">
                    <span class="apitoken-scope-resource">
                      <span class="apitoken-scope-resource-name">{resource}</span>
                      <span class="field-hint apitoken-scope-gloss">{resourceGloss(resource)}</span>
                    </span>
                    <For each={data()?.actions}>
                      {(action) => {
                        const scope = `${resource}:${action}`
                        return (
                          <input
                            type="checkbox"
                            class="apitoken-scope-check"
                            aria-label={scope}
                            title={scopeTitle(resource, action)}
                            checked={scopes().has(scope)}
                            onChange={(event) => toggleScope(scope, event.currentTarget.checked)}
                          />
                        )
                      }}
                    </For>
                  </div>
                )}
              </For>
            </div>
          </Show>
        </fieldset>

        <label class="field">
          <span>{m.settings_apitoken_expiry_label()}</span>
          <DateField value={expiresAt()} onChange={setExpiresAt} calendar />
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

      <h3 class="apitoken-subheading">{m.settings_apitoken_existing_heading()}</h3>

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
    </div>
  )
}
