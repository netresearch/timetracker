import { createSignal, Show } from 'solid-js'

import { apiErrorMessage } from '../../api/client'
import { patchSettings } from '../../api/settings'
import { appConfig } from '../../config'
import { m } from '../../paraglide/messages.js'

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

/** Per-user Personio attendance-export opt-in (ADR-024). Saves on toggle via
 *  PATCH /api/v2/settings — sending ONLY this field, so a disabled control
 *  (Personio unconfigured) can never flip the stored value. */
export function PersonioOptIn() {
  const config = appConfig()
  const [enabled, setEnabled] = createSignal(config.personioSyncEnabled)
  const [status, setStatus] = createSignal<Status>({ kind: 'idle' })
  const statusMessage = () => {
    const current = status()

    return current.kind === 'error' ? current.message : ''
  }

  async function toggle(next: boolean) {
    const previous = enabled()
    setEnabled(next)
    setStatus({ kind: 'saving' })
    try {
      const result = await patchSettings({ personio_sync_enabled: next })
      setEnabled(result.personio_sync_enabled)
      setStatus({ kind: 'ok' })
    } catch (error) {
      setEnabled(previous)
      setStatus({ kind: 'error', message: apiErrorMessage(error, m.settings_save_error()) })
    }
  }

  return (
    <fieldset class="settings-group">
      <legend>{m.settings_personio_sync()}</legend>
      <label class="field-check">
        <input
          type="checkbox"
          name="personio_sync_enabled"
          checked={enabled()}
          disabled={!config.personioConfigured || status().kind === 'saving'}
          onChange={(event) => void toggle(event.currentTarget.checked)}
        />
        <span>{m.settings_personio_sync()}</span>
        <Show
          when={config.personioConfigured}
          fallback={<small class="field-hint">{m.settings_personio_sync_unavailable()}</small>}
        >
          <small class="field-hint">{m.settings_personio_sync_help()}</small>
        </Show>
      </label>
      <Show when={status().kind === 'ok'}>
        <span role="status" class="form-status is-ok">{m.settings_saved()}</span>
      </Show>
      <Show when={status().kind === 'error'}>
        <span role="alert" class="form-status is-error">{statusMessage()}</span>
      </Show>
    </fieldset>
  )
}
