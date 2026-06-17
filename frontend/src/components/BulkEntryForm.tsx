import { useQuery } from '@tanstack/solid-query'
import { createSignal, For, Show } from 'solid-js'

import { apiErrorMessage, postForm } from '../api/client'
import { presetsQuery } from '../api/queries'
import { m } from '../paraglide/messages.js'

type Status =
  | { kind: 'idle' | 'saving' }
  | { kind: 'ok'; message: string }
  | { kind: 'error'; message: string }

/**
 * Bulk-entry form (preset + date range → POST /tracking/bulkentry). Used as the
 * Extras page body and inside the Worklog toolbar's "Bulk entry" modal, so the
 * form lives in one place. `onSaved` lets a host refetch/close after a success.
 */
export function BulkEntryForm(props: { onSaved?: () => void }) {
  const presets = useQuery(presetsQuery)

  const [preset, setPreset] = createSignal(0)
  const [startDate, setStartDate] = createSignal('')
  const [endDate, setEndDate] = createSignal('')
  const [useContract, setUseContract] = createSignal(true)
  const [startTime, setStartTime] = createSignal('08:00')
  const [endTime, setEndTime] = createSignal('16:00')
  const [skipWeekend, setSkipWeekend] = createSignal(true)
  const [skipHolidays, setSkipHolidays] = createSignal(true)
  const [status, setStatus] = createSignal<Status>({ kind: 'idle' })
  const statusMessage = () => {
    const current = status()

    return current.kind === 'ok' || current.kind === 'error' ? current.message : ''
  }

  function clientError(): string | null {
    if (preset() <= 0) {
      return m.extras_choose_preset()
    }
    if (startDate() === '' || endDate() === '') {
      return m.extras_date_required()
    }
    if (startDate() > endDate()) {
      return m.extras_date_order()
    }
    if (!useContract() && (startTime() === '' || endTime() === '' || startTime() >= endTime())) {
      return m.extras_time_order()
    }

    return null
  }

  async function onSubmit(event: SubmitEvent) {
    event.preventDefault()
    const error = clientError()
    if (error !== null) {
      setStatus({ kind: 'error', message: error })

      return
    }

    setStatus({ kind: 'saving' })
    try {
      const message = await postForm('/tracking/bulkentry', {
        preset: preset(),
        startdate: startDate(),
        enddate: endDate(),
        starttime: `${startTime()}:00`,
        endtime: `${endTime()}:00`,
        usecontract: useContract() ? 1 : 0,
        skipweekend: skipWeekend() ? 1 : 0,
        skipholidays: skipHolidays() ? 1 : 0,
      })
      setStatus({ kind: 'ok', message })
      props.onSaved?.()
    } catch (caught) {
      // The endpoint returns the validation/error message as the 422 body.
      const message = apiErrorMessage(caught, m.app_load_error())
      setStatus({ kind: 'error', message })
    }
  }

  return (
    <>
      <Show when={presets.isError}>
        <p role="alert">{m.app_load_error()}</p>
      </Show>
      <form class="stack-form" onSubmit={(event) => void onSubmit(event)}>
        <label class="field">
          <span>{m.extras_preset()}</span>
          <select
            disabled={presets.isPending}
            value={preset()}
            onInput={(event) => setPreset(Number(event.currentTarget.value))}
          >
            <option value="0">—</option>
            <For each={presets.data ?? []}>
              {(option) => <option value={option.id}>{option.label}</option>}
            </For>
          </select>
        </label>

        <div class="field-row">
          <label class="field">
            <span>{m.extras_start_date()}</span>
            <input type="date" value={startDate()} onInput={(e) => setStartDate(e.currentTarget.value)} />
          </label>
          <label class="field">
            <span>{m.extras_end_date()}</span>
            <input type="date" value={endDate()} onInput={(e) => setEndDate(e.currentTarget.value)} />
          </label>
        </div>

        <label class="field-check">
          <input type="checkbox" checked={useContract()} onInput={(e) => setUseContract(e.currentTarget.checked)} />
          <span>{m.extras_use_contract()}</span>
        </label>

        <Show when={!useContract()}>
          <div class="field-row">
            <label class="field">
              <span>{m.extras_start_time()}</span>
              <input type="time" value={startTime()} onInput={(e) => setStartTime(e.currentTarget.value)} />
            </label>
            <label class="field">
              <span>{m.extras_end_time()}</span>
              <input type="time" value={endTime()} onInput={(e) => setEndTime(e.currentTarget.value)} />
            </label>
          </div>
          <p class="field-hint">{m.extras_time_hint()}</p>
        </Show>

        <label class="field-check">
          <input type="checkbox" checked={skipWeekend()} onInput={(e) => setSkipWeekend(e.currentTarget.checked)} />
          <span>{m.extras_skip_weekend()}</span>
        </label>
        <label class="field-check">
          <input type="checkbox" checked={skipHolidays()} onInput={(e) => setSkipHolidays(e.currentTarget.checked)} />
          <span>{m.extras_skip_holidays()}</span>
        </label>

        <div class="form-actions">
          <button type="submit" class="primary-button" disabled={status().kind === 'saving'}>
            {status().kind === 'saving' ? m.app_saving() : m.extras_submit()}
          </button>
          <Show when={status().kind === 'ok'}>
            <span role="status" class="form-status is-ok">{statusMessage()}</span>
          </Show>
          <Show when={status().kind === 'error'}>
            <span role="alert" class="form-status is-error">{statusMessage()}</span>
          </Show>
        </div>
      </form>
    </>
  )
}
