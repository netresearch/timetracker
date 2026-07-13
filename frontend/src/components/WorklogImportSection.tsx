import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import { activitiesQuery, ticketSystemsQuery } from '../api/queries'
import { createSyncRun, worklogSyncKeys, type CreateRunPayload, type SyncRun } from '../api/worklogSync'
import { appConfig } from '../config'
import { isoDate } from '../lib/format'
import { DateField } from './DateField'
import { HelpPopover } from './HelpPopover'
import { SyncRunSummary } from './SyncRunSummary'
import { m } from '../paraglide/messages.js'

// The default import window is the current month up to today: the common case is
// "pull in what I logged in Jira this month".
function firstOfMonth(): string {
  const now = new Date()

  return isoDate(new Date(now.getFullYear(), now.getMonth(), 1))
}

/**
 * Settings → self-service Jira worklog import (ADR-023 use case 1). A two-step
 * flow mirroring the Security section's multi-`<Show>` shape: **Preview** runs a
 * dry-run import (`dry_run:true`) and shows its {@link SyncRunSummary}, then
 * **Execute import** runs it for real (`dry_run:false`). The run is scoped to the
 * current user (self-service; the backend enforces that for non-admins anyway),
 * so no user picker is offered here.
 */
export function WorklogImportSection(): JSX.Element {
  const queryClient = useQueryClient()
  const ticketSystems = useQuery(ticketSystemsQuery)
  const activities = useQuery(activitiesQuery)

  const [ticketSystemId, setTicketSystemId] = createSignal(0)
  const [from, setFrom] = createSignal(firstOfMonth())
  const [to, setTo] = createSignal(isoDate(new Date()))
  const [activityId, setActivityId] = createSignal(0)
  const [busy, setBusy] = createSignal(false)
  const [error, setError] = createSignal('')
  // The dry-run summary (Preview) and the executed-run summary (Execute) are held
  // separately so Execute replaces the preview with the real result + a status.
  const [preview, setPreview] = createSignal<SyncRun | null>(null)
  const [result, setResult] = createSignal<SyncRun | null>(null)

  const canRun = (): boolean => ticketSystemId() > 0 && !busy()

  function payload(dryRun: boolean): CreateRunPayload {
    return {
      type: 'import',
      ticket_system_id: ticketSystemId(),
      from: from(),
      to: to(),
      users: [appConfig().userName],
      ...(activityId() > 0 ? { default_activity_id: activityId() } : {}),
      dry_run: dryRun,
    }
  }

  async function runImport(dryRun: boolean): Promise<void> {
    setBusy(true)
    setError('')
    try {
      const run = await createSyncRun(payload(dryRun))
      if (dryRun) {
        setPreview(run)
        setResult(null)
      } else {
        setResult(run)
        setPreview(null)
        // A real import can park entries as conflicts — refresh any open list.
        void queryClient.invalidateQueries({ queryKey: worklogSyncKeys.conflicts })
      }
    } catch (caught) {
      setError(apiErrorMessage(caught, m.worklogsync_import_error()))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div class="stack-form">
      <fieldset class="settings-group">
        <legend>
          {m.worklogsync_import_title()}
          <HelpPopover topic={m.worklogsync_import_title()}>{m.settings_help_import_dryrun()}</HelpPopover>
        </legend>
        <p class="settings-section-hint">{m.worklogsync_import_intro()}</p>

        <div class="security-block">
          <label class="field">
            <span>{m.worklogsync_ticket_system()}</span>
            <select
              value={ticketSystemId()}
              disabled={busy()}
              onChange={(event) => setTicketSystemId(Number(event.currentTarget.value))}
            >
              <option value={0}>—</option>
              <For each={ticketSystems.data ?? []}>
                {(option) => <option value={option.id}>{option.label}</option>}
              </For>
            </select>
          </label>

          <label class="field">
            <span>{m.worklogsync_from()}</span>
            <DateField value={from()} onChange={setFrom} disabled={busy()} />
          </label>

          <label class="field">
            <span>{m.worklogsync_to()}</span>
            <DateField value={to()} onChange={setTo} disabled={busy()} />
          </label>

          <label class="field">
            <span>{m.worklogsync_default_activity()}</span>
            <select
              value={activityId()}
              disabled={busy()}
              onChange={(event) => setActivityId(Number(event.currentTarget.value))}
            >
              <option value={0}>—</option>
              <For each={activities.data ?? []}>
                {(option) => <option value={option.id}>{option.label}</option>}
              </For>
            </select>
          </label>

          <div class="form-actions">
            <button type="button" class="primary-button" disabled={!canRun()} onClick={() => void runImport(true)}>
              {busy() ? m.app_saving() : m.worklogsync_preview()}
            </button>
          </div>

          {/* Preview result: a dry-run summary with an explicit "nothing yet" note
              and the button that commits the import. */}
          <Show when={preview()}>
            {(run) => (
              <div class="sync-preview">
                <p role="status" class="form-status is-ok">{m.worklogsync_dryrun_note()}</p>
                <SyncRunSummary run={run()} />
                <div class="form-actions">
                  <button type="button" class="primary-button" disabled={!canRun()} onClick={() => void runImport(false)}>
                    {busy() ? m.app_saving() : m.worklogsync_execute()}
                  </button>
                </div>
              </div>
            )}
          </Show>

          {/* Final result after a real import. */}
          <Show when={result()}>
            {(run) => (
              <div class="sync-result">
                <p role="status" class="form-status is-ok">{m.worklogsync_import_done()}</p>
                <SyncRunSummary run={run()} />
              </div>
            )}
          </Show>

          <Show when={error()}>
            <span role="alert" class="form-status is-error">{error()}</span>
          </Show>
        </div>
      </fieldset>
    </div>
  )
}
