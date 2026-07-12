import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createEffect, createMemo, createSignal, For, Show, type JSX } from 'solid-js'

import { apiErrorMessage } from '../api/client'
import {
  confirmEmployeeMatches,
  type EmployeeMatchProposal,
  employeeMatchesQuery,
  type MatchSource,
  personioEmployeeMatchKeys,
} from '../api/personioEmployeeMatch'
import { hasRole } from '../config'
import { m } from '../paraglide/messages.js'

const sourceLabels: Record<MatchSource, () => string> = {
  email: m.personiomatch_source_email,
  name: m.personiomatch_source_name,
}
const sourceLabel = (source: MatchSource): string => (sourceLabels[source] ?? (() => source))()

/**
 * The admin Personio employee-match review screen (ADR-024 P3): list each TT
 * user that has no Personio employee id yet with its proposed match, let the
 * admin confirm per row, and post the confirmed mappings. Confident e-mail
 * matches are pre-checked; the weaker firstname.lastname matches start unchecked
 * so the admin looks before applying. Rendered as a non-CRUD Administration
 * sub-page (see Admin.tsx) behind the ROLE_ADMIN route guard.
 */
export default function PersonioEmployeeMatch(): JSX.Element {
  return (
    <Show when={hasRole('ROLE_ADMIN')}>
      <PersonioEmployeeMatchArea />
    </Show>
  )
}

function PersonioEmployeeMatchArea(): JSX.Element {
  const queryClient = useQueryClient()
  const proposals = useQuery(() => employeeMatchesQuery())

  // Per-user confirm state, keyed by user id. Seeded once the proposals arrive
  // (and reseeded when the set changes, e.g. after a confirm refresh), with the
  // confident e-mail matches pre-checked.
  const [checked, setChecked] = createSignal<Record<number, boolean>>({})
  let seedKey = ''
  createEffect(() => {
    const data = proposals.data
    if (!data) {
      return
    }
    const key = data.proposals.map((proposal) => `${proposal.user_id}:${proposal.source}`).join(',')
    if (key === seedKey) {
      return
    }
    seedKey = key
    const seeded: Record<number, boolean> = {}
    for (const proposal of data.proposals) {
      seeded[proposal.user_id] = proposal.source === 'email'
    }
    setChecked(seeded)
  })

  const isChecked = (userId: number): boolean => checked()[userId] ?? false

  function toggle(userId: number, value: boolean): void {
    setChecked((prev) => ({ ...prev, [userId]: value }))
  }

  const [busy, setBusy] = createSignal(false)
  const [error, setError] = createSignal('')
  const [appliedCount, setAppliedCount] = createSignal<number | null>(null)

  const selected = createMemo(() =>
    (proposals.data?.proposals ?? [])
      .filter((proposal) => isChecked(proposal.user_id))
      .map((proposal) => ({ user_id: proposal.user_id, person_id: proposal.person_id })),
  )

  const canConfirm = (): boolean => selected().length > 0 && !busy()

  async function confirmSelected(): Promise<void> {
    const payload = selected()
    if (payload.length === 0) {
      return
    }
    setBusy(true)
    setError('')
    setAppliedCount(null)
    try {
      const result = await confirmEmployeeMatches(payload)
      setAppliedCount(result.applied.length)
      // The mapped users now have an id — refresh so they drop from the list.
      void queryClient.invalidateQueries({ queryKey: personioEmployeeMatchKeys.proposals })
    } catch (caught) {
      setError(apiErrorMessage(caught, m.personiomatch_confirm_error()))
    } finally {
      setBusy(false)
    }
  }

  const hasProposals = (): boolean => (proposals.data?.proposals.length ?? 0) > 0

  return (
    <div class="personio-employee-match">
      <section class="status-group">
        <h2 class="status-group-title">{m.personiomatch_admin_title()}</h2>
        <p class="field-hint">{m.personiomatch_intro()}</p>

        <Show when={appliedCount() !== null}>
          <p role="status" class="form-status is-ok">
            {m.personiomatch_applied({ count: String(appliedCount() ?? 0) })}
          </p>
        </Show>

        <Show
          when={hasProposals()}
          fallback={<p class="sync-counters-empty">{m.personiomatch_empty()}</p>}
        >
          <table class="data-table">
            <thead>
              <tr>
                <th scope="col">{m.personiomatch_col_user()}</th>
                <th scope="col">{m.personiomatch_col_person()}</th>
                <th scope="col">{m.personiomatch_col_source()}</th>
                <th scope="col">{m.personiomatch_col_confirm()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={proposals.data?.proposals ?? []}>
                {(proposal: EmployeeMatchProposal) => {
                  const severity = proposal.source === 'email' ? 'ok' : 'warn'

                  return (
                    <tr>
                      <td>
                        <code>{proposal.username}</code>
                      </td>
                      <td>
                        {proposal.person_name} <span class="import-derived-name">#{proposal.person_id}</span>
                      </td>
                      <td>
                        <span class={`import-source is-${severity}`}>{sourceLabel(proposal.source)}</span>
                      </td>
                      <td>
                        <label class="import-confirm">
                          <input
                            type="checkbox"
                            aria-label={`${m.personiomatch_col_confirm()} — ${proposal.username}`}
                            checked={isChecked(proposal.user_id)}
                            disabled={busy()}
                            onInput={(event) => toggle(proposal.user_id, event.currentTarget.checked)}
                          />
                        </label>
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
              {busy() ? m.app_saving() : m.personiomatch_confirm_selected()}
            </button>
          </div>

          <Show when={error()}>
            <span role="alert" class="form-status is-error">{error()}</span>
          </Show>
        </Show>
      </section>
    </div>
  )
}
