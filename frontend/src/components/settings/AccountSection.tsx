import { createSignal, For, Show } from 'solid-js'

import { apiErrorMessage } from '../../api/client'
import { patchSettings } from '../../api/settings'
import { appConfig, type AppConfig } from '../../config'
import { m } from '../../paraglide/messages.js'

// Only the locales the UI actually ships translations for (the same SET as
// project.inlang/settings.json — the display order here is deliberate and
// independent of it). Labels are endonyms on purpose — a user locked into the
// wrong language must still recognise their own.
const LANGUAGES = [
  { value: 'de', label: 'Deutsch' },
  { value: 'en', label: 'English' },
  { value: 'es', label: 'Español' },
  { value: 'fr', label: 'Français' },
  { value: 'ru', label: 'Русский' },
]

interface BoolSetting {
  name: 'show_empty_line' | 'suggest_time' | 'show_future'
  label: () => string
  initial: (c: AppConfig) => boolean
}

const BOOL_SETTINGS: BoolSetting[] = [
  { name: 'show_empty_line', label: () => m.settings_show_empty_line(), initial: (c) => c.showEmptyLine },
  { name: 'suggest_time', label: () => m.settings_suggest_time(), initial: (c) => c.suggestTime },
  { name: 'show_future', label: () => m.settings_show_future(), initial: (c) => c.showFuture },
]

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

/** Account settings — persisted server-side (PATCH /api/v2/settings),
 *  applied on every device. The only settings section with a Save button. */
export function AccountSection() {
  const config = appConfig()
  const [status, setStatus] = createSignal<Status>({ kind: 'idle' })
  const statusMessage = () => {
    const current = status()

    return current.kind === 'error' ? current.message : ''
  }

  async function onSubmit(event: SubmitEvent) {
    event.preventDefault()
    const form = event.currentTarget as HTMLFormElement
    const data = new FormData(form)
    const locale = String(data.get('locale') ?? config.locale)

    setStatus({ kind: 'saving' })
    try {
      const result = await patchSettings({
        locale,
        show_empty_line: data.get('show_empty_line') !== null,
        suggest_time: data.get('suggest_time') !== null,
        show_future: data.get('show_future') !== null,
        min_entry_duration: Number(data.get('min_entry_duration') ?? config.minEntryDuration),
      })

      // All UI strings are locale-bound at load time; a locale change needs a
      // full reload (the URL — and so the active section — is unchanged).
      if (result.locale !== config.locale) {
        window.location.reload()

        return
      }

      setStatus({ kind: 'ok' })
    } catch (error) {
      setStatus({ kind: 'error', message: apiErrorMessage(error, m.settings_save_error()) })
    }
  }

  return (
    <form class="stack-form" onSubmit={(event) => void onSubmit(event)}>
      <fieldset class="settings-group">
        <legend>{m.settings_section_account()}</legend>
        <p class="settings-section-hint">{m.settings_section_account_hint()}</p>

        <label class="field">
          <span>{m.settings_language()}</span>
          <select name="locale" value={config.locale}>
            <For each={LANGUAGES}>
              {(lang) => <option value={lang.value}>{lang.label}</option>}
            </For>
          </select>
        </label>

        <For each={BOOL_SETTINGS}>
          {(setting) => (
            <label class="field-check">
              <input type="checkbox" name={setting.name} checked={setting.initial(config)} />
              <span>{setting.label()}</span>
            </label>
          )}
        </For>

        {/* Server setting: a new entry's end pre-fills to start + this many minutes. */}
        <label class="field">
          <span>{m.settings_min_entry_duration()}</span>
          <input type="number" name="min_entry_duration" min="0" max="1440" step="5" value={config.minEntryDuration} />
          <small class="field-hint">{m.settings_min_entry_duration_hint()}</small>
        </label>
      </fieldset>

      <div class="form-actions">
        <button type="submit" class="primary-button" disabled={status().kind === 'saving'}>
          {status().kind === 'saving' ? m.app_saving() : m.app_save()}
        </button>
        <Show when={status().kind === 'ok'}>
          <span role="status" class="form-status is-ok">{m.settings_saved()}</span>
        </Show>
        <Show when={status().kind === 'error'}>
          <span role="alert" class="form-status is-error">{statusMessage()}</span>
        </Show>
      </div>
    </form>
  )
}
