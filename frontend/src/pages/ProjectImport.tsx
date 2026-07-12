import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createEffect, createMemo, createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import {
  confirmProjectImport,
  projectImportKeys,
  type ConfirmResultRow,
  type ConfirmRow,
  type DerivationSource,
  type Proposal,
  proposalsQuery,
} from '../api/projectImport'
import { customersQuery, ticketSystemsQuery, type NamedOption } from '../api/queries'
import { hasRole } from '../config'
import { m } from '../paraglide/messages.js'

// The three-level confidence of a derivation source: confident (green) picks are
// pre-selected and immediately confirmable; a category fallback is flagged amber;
// the rest carry no confident customer, so the row needs a human pick before it
// can be confirmed (red — "needs attention").
type Severity = 'ok' | 'warn' | 'attention'

const SEVERITY: Record<DerivationSource, Severity> = {
  tempo: 'ok',
  'tempo-default': 'ok',
  category: 'warn',
  ambiguous: 'attention',
  none: 'attention',
  error: 'attention',
  'not-a-project': 'attention',
}

const sourceLabels: Record<DerivationSource, () => string> = {
  tempo: m.projectimport_source_tempo,
  'tempo-default': m.projectimport_source_tempo_default,
  category: m.projectimport_source_category,
  ambiguous: m.projectimport_source_ambiguous,
  none: m.projectimport_source_none,
  error: m.projectimport_source_error,
  'not-a-project': m.projectimport_source_not_a_project,
}
const sourceLabel = (source: DerivationSource): string =>
  (sourceLabels[source] ?? (() => source))()

const resultLabels: Record<ConfirmResultRow['status'], () => string> = {
  created: m.projectimport_result_created,
  existing: m.projectimport_result_existing,
}

// Per-row editing state. `choice` is the override <select> value: '' (none),
// 'new' (create/find by the entered name), or a stringified existing customer id.
interface RowState {
  choice: string
  newName: string
  confirm: boolean
}

const EMPTY_ROW: RowState = { choice: '', newName: '', confirm: false }

// A row has a customer once an existing one is picked, or a non-blank new name is
// entered. Its confirm checkbox stays disabled until then (red rows start here).
function isResolved(row: RowState): boolean {
  if (row.choice === '') {
    return false
  }
  if (row.choice === 'new') {
    return row.newName.trim() !== ''
  }

  return true
}

// Seed a row from its proposal: preselect the existing customer whose name equals
// the derived one, else prefill the new-customer name with the derived name, else
// leave it empty (the human must pick).
function defaultRow(proposal: Proposal, customers: NamedOption[]): RowState {
  const derived = (proposal.derived_customer_name ?? '').trim()
  if (derived === '') {
    return { ...EMPTY_ROW }
  }
  const match = customers.find((customer) => customer.label.trim().toLowerCase() === derived.toLowerCase())
  if (match) {
    return { choice: String(match.id), newName: '', confirm: false }
  }

  return { choice: 'new', newName: derived, confirm: false }
}

/**
 * The admin project-import review-and-confirm screen (ADR-026 P1c): for a chosen
 * ticket system, list each parked Jira prefix with its derived Customer + Project
 * proposal, let the admin override/confirm per row, and post the confirmed rows.
 * Rendered as a non-CRUD Administration sub-page (see Admin.tsx) behind the
 * ROLE_ADMIN route guard; the in-component gate keeps a direct render honest.
 */
export default function ProjectImport(): JSX.Element {
  return (
    <Show when={hasRole('ROLE_ADMIN')}>
      <ProjectImportArea />
    </Show>
  )
}

function ProjectImportArea(): JSX.Element {
  const queryClient = useQueryClient()
  const ticketSystems = useQuery(ticketSystemsQuery)
  const customers = useQuery(customersQuery)

  const [ticketSystemId, setTicketSystemId] = createSignal(0)
  const proposals = useQuery(() => proposalsQuery(ticketSystemId()))

  // Per-prefix editing state, seeded once both the proposals and the customer
  // list are in — reseeded whenever the proposal set changes (ticket-system
  // switch or a post-confirm refresh), which also discards stale edits.
  const [rows, setRows] = createSignal<Record<string, RowState>>({})
  let seedKey = ''
  createEffect(() => {
    const data = proposals.data
    const custs = customers.data
    if (!data || !custs) {
      return
    }
    const key = `${ticketSystemId()}|${data.proposals.map((proposal) => proposal.jira_id_prefix).join(',')}`
    if (key === seedKey) {
      return
    }
    seedKey = key
    const seeded: Record<string, RowState> = {}
    for (const proposal of data.proposals) {
      seeded[proposal.jira_id_prefix] = defaultRow(proposal, custs)
    }
    setRows(seeded)
  })

  const rowState = (prefix: string): RowState => rows()[prefix] ?? EMPTY_ROW

  function updateRow(prefix: string, patch: Partial<RowState>): void {
    setRows((prev) => {
      const next: RowState = { ...(prev[prefix] ?? EMPTY_ROW), ...patch }
      // A row that loses its customer can no longer stay confirmed.
      if (!isResolved(next)) {
        next.confirm = false
      }

      return { ...prev, [prefix]: next }
    })
  }

  const [busy, setBusy] = createSignal(false)
  const [error, setError] = createSignal('')
  const [results, setResults] = createSignal<ConfirmResultRow[]>([])

  const resultFor = (prefix: string): ConfirmResultRow | undefined =>
    results().find((row) => row.jira_key.toUpperCase() === prefix.toUpperCase())

  // The confirmed + resolved rows, as the POST body. A memo so the button's
  // enabled state and the click share one computation.
  const selectedRows = createMemo((): ConfirmRow[] => {
    const state = rows()
    const tsId = ticketSystemId()
    const out: ConfirmRow[] = []
    for (const proposal of proposals.data?.proposals ?? []) {
      const row = state[proposal.jira_id_prefix]
      if (!row || !row.confirm || !isResolved(row)) {
        continue
      }
      const base = {
        jira_key: proposal.jira_id_prefix,
        project_name: (proposal.project_name ?? '').trim() || proposal.jira_id_prefix,
        ticket_system_id: tsId,
      }
      out.push(
        row.choice === 'new'
          ? { ...base, customer_name: row.newName.trim() }
          : { ...base, customer_id: Number(row.choice) },
      )
    }

    return out
  })

  const canConfirm = (): boolean => selectedRows().length > 0 && !busy()

  async function confirmSelected(): Promise<void> {
    const payload = selectedRows()
    if (payload.length === 0) {
      return
    }
    setBusy(true)
    setError('')
    try {
      const result = await confirmProjectImport(payload)
      setResults(result.projects)
      // The confirmed prefixes now resolve — refresh so they drop from the list.
      void queryClient.invalidateQueries({ queryKey: projectImportKeys.proposals })
    } catch (caught) {
      setError(apiErrorMessage(caught, m.projectimport_confirm_error()))
    } finally {
      setBusy(false)
    }
  }

  const hasProposals = (): boolean => (proposals.data?.proposals.length ?? 0) > 0

  return (
    <div class="project-import">
      <section class="status-group">
        <h2 class="status-group-title">{m.projectimport_admin_title()}</h2>
        <p class="field-hint">{m.projectimport_intro()}</p>

        <div class="stack-form">
          <label class="field">
            <span>{m.worklogsync_ticket_system()}</span>
            <select
              id="projectimport-ticket"
              value={ticketSystemId()}
              disabled={busy()}
              onChange={(event) => {
                setResults([])
                setError('')
                setTicketSystemId(Number(event.currentTarget.value))
              }}
            >
              <option value={0}>—</option>
              <For each={ticketSystems.data ?? []}>
                {(option) => <option value={option.id}>{option.label}</option>}
              </For>
            </select>
          </label>
        </div>

        <Show when={ticketSystemId() > 0}>
          <Show
            when={hasProposals()}
            fallback={<p class="sync-counters-empty">{m.projectimport_empty()}</p>}
          >
            <table class="data-table">
              <thead>
                <tr>
                  <th scope="col">{m.projectimport_col_prefix()}</th>
                  <th scope="col">{m.projectimport_col_project()}</th>
                  <th scope="col">{m.projectimport_col_derived()}</th>
                  <th scope="col">{m.projectimport_col_override()}</th>
                  <th scope="col">{m.projectimport_col_confirm()}</th>
                </tr>
              </thead>
              <tbody>
                <For each={proposals.data?.proposals ?? []}>
                  {(proposal) => {
                    const prefix = proposal.jira_id_prefix
                    const severity = SEVERITY[proposal.derivation_source] ?? 'attention'

                    return (
                      <tr class={severity === 'attention' ? 'is-attention' : undefined}>
                        <td>
                          <code>{prefix}</code>
                        </td>
                        <td>{proposal.project_name ?? '—'}</td>
                        <td>
                          <div class="import-derived">
                            <span class={`import-source is-${severity}`}>
                              {sourceLabel(proposal.derivation_source)}
                            </span>
                            <Show when={proposal.derived_customer_name}>
                              {(name) => <span class="import-derived-name">{name()}</span>}
                            </Show>
                            <Show
                              when={
                                proposal.derivation_source === 'ambiguous' &&
                                proposal.candidate_customers.length > 0
                              }
                            >
                              <p class="import-candidates">
                                {m.projectimport_candidates()}: {proposal.candidate_customers.join(', ')}
                              </p>
                            </Show>
                          </div>
                        </td>
                        <td>
                          <div class="import-customer-cell">
                            <select
                              aria-label={`${m.projectimport_col_override()} — ${prefix}`}
                              value={rowState(prefix).choice}
                              disabled={busy()}
                              onChange={(event) => updateRow(prefix, { choice: event.currentTarget.value })}
                            >
                              <option value="">{m.projectimport_customer_choose()}</option>
                              <For each={customers.data ?? []}>
                                {(customer) => <option value={String(customer.id)}>{customer.label}</option>}
                              </For>
                              <option value="new">{m.projectimport_customer_new_option()}</option>
                            </select>
                            <Show when={rowState(prefix).choice === 'new'}>
                              <input
                                type="text"
                                aria-label={`${m.projectimport_customer_new_label()} — ${prefix}`}
                                placeholder={m.projectimport_customer_new_label()}
                                value={rowState(prefix).newName}
                                disabled={busy()}
                                onInput={(event) => updateRow(prefix, { newName: event.currentTarget.value })}
                              />
                            </Show>
                          </div>
                        </td>
                        <td>
                          <div class="import-confirm-cell">
                            <label class="import-confirm">
                              <input
                                type="checkbox"
                                aria-label={`${m.projectimport_col_confirm()} — ${prefix}`}
                                checked={rowState(prefix).confirm}
                                disabled={busy() || !isResolved(rowState(prefix))}
                                onInput={(event) => updateRow(prefix, { confirm: event.currentTarget.checked })}
                              />
                            </label>
                            <Show when={resultFor(prefix)}>
                              {(result) => (
                                <span class={`import-result is-${result().status}`}>
                                  {(resultLabels[result().status] ?? (() => result().status))()}
                                </span>
                              )}
                            </Show>
                          </div>
                        </td>
                      </tr>
                    )
                  }}
                </For>
              </tbody>
            </table>

            <div class="form-actions">
              <button
                type="button"
                class="primary-button"
                disabled={!canConfirm()}
                onClick={() => void confirmSelected()}
              >
                {busy() ? m.app_saving() : m.projectimport_confirm_selected()}
              </button>
            </div>

            <Show when={error()}>
              <span role="alert" class="form-status is-error">{error()}</span>
            </Show>
          </Show>
        </Show>
      </section>
    </div>
  )
}
