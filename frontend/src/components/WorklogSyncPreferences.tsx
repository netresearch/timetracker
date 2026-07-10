import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import {
  putWorklogSyncPreferences,
  worklogSyncKeys,
  worklogSyncPreferencesQuery,
  type PutPreferencePayload,
  type WorklogSyncPreference,
} from '../api/worklogSync'
import { m } from '../paraglide/messages.js'

/**
 * Settings → per-user Jira worklog sync opt-in (ADR-023 amendment). Lists the
 * caller's connected Jira ticket systems and lets them opt their own worklogs
 * into the nightly sync (`sync_enabled`), each running under their own Jira
 * token. A project lead / admin additionally sees a "sync all worklogs I can
 * access" toggle (`sync_all`) — the server tells us via `can_sync_all` whether
 * to offer it, so the covered colleagues are synced under the PO's token.
 *
 * Each toggle writes the caller's own row via PUT, then invalidates the
 * preferences cache so the refetch reflects the persisted state.
 */
export function WorklogSyncPreferences(): JSX.Element {
  const queryClient = useQueryClient()
  const preferences = useQuery(worklogSyncPreferencesQuery)
  // Which ticket-system row is mid-save (per-row busy), plus shared feedback.
  const [busyId, setBusyId] = createSignal<number | null>(null)
  const [saved, setSaved] = createSignal(false)
  const [error, setError] = createSignal('')

  const list = (): WorklogSyncPreference[] => preferences.data?.preferences ?? []
  const canSyncAll = (): boolean => preferences.data?.can_sync_all ?? false

  async function toggle(
    preference: WorklogSyncPreference,
    change: { sync_enabled?: boolean; sync_all?: boolean },
  ): Promise<void> {
    setBusyId(preference.ticket_system_id)
    setError('')
    // Clear the previous success so the live region re-announces this save.
    setSaved(false)
    // The server always sets sync_enabled; send the current value when only
    // sync_all changed. sync_all is sent only when the caller may set it.
    const payload: PutPreferencePayload = {
      ticket_system_id: preference.ticket_system_id,
      sync_enabled: change.sync_enabled ?? preference.sync_enabled,
    }
    if (canSyncAll()) {
      payload.sync_all = change.sync_all ?? preference.sync_all
    }
    try {
      await putWorklogSyncPreferences(payload)
      setSaved(true)
      void queryClient.invalidateQueries({ queryKey: worklogSyncKeys.preferences })
    } catch (caught) {
      setError(apiErrorMessage(caught, m.worklogsync_prefs_error()))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div class="stack-form">
      <fieldset class="settings-group">
        <legend>{m.worklogsync_prefs_title()}</legend>
        <p class="settings-section-hint">{m.worklogsync_prefs_intro()}</p>

        <Show
          when={list().length > 0}
          fallback={
            <Show when={!preferences.isPending}>
              <p class="field-hint">{m.worklogsync_prefs_none()}</p>
            </Show>
          }
        >
          <For each={list()}>
            {(preference) => (
              <div class="security-block">
                <h3 class="security-heading">{preference.ticket_system_name}</h3>

                <label class="field-check">
                  <input
                    type="checkbox"
                    checked={preference.sync_enabled}
                    disabled={busyId() === preference.ticket_system_id}
                    onChange={(event) =>
                      void toggle(preference, { sync_enabled: event.currentTarget.checked })
                    }
                  />
                  <span>{m.worklogsync_prefs_enable()}</span>
                </label>
                <p class="field-hint">{m.worklogsync_prefs_enable_hint()}</p>

                <Show when={canSyncAll()}>
                  <label class="field-check">
                    <input
                      type="checkbox"
                      checked={preference.sync_all}
                      disabled={busyId() === preference.ticket_system_id}
                      onChange={(event) =>
                        void toggle(preference, { sync_all: event.currentTarget.checked })
                      }
                    />
                    <span>{m.worklogsync_prefs_sync_all()}</span>
                  </label>
                  <p class="field-hint">{m.worklogsync_prefs_sync_all_hint()}</p>
                </Show>
              </div>
            )}
          </For>
        </Show>

        <Show when={saved()}>
          <span role="status" class="form-status is-ok">{m.worklogsync_prefs_saved()}</span>
        </Show>
        <Show when={error()}>
          <span role="alert" class="form-status is-error">{error()}</span>
        </Show>
      </fieldset>
    </div>
  )
}
