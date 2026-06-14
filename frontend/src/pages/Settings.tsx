import { createSignal, For, Show } from 'solid-js'

import { apiErrorMessage, postForm } from '../api/client'
import { appConfig, type AppConfig } from '../config'
import { m } from '../paraglide/messages.js'

interface SaveResponse {
  success: boolean
  locale: string
  message: string
}

// Only the locales the UI actually ships translations for (paraglide compiles
// en + de; AppConfig.locale is typed 'en' | 'de'). Offering es/fr/ru let users
// pick a language that then rendered as the base locale — a broken choice.
const LANGUAGES = [
  { value: 'de', label: 'Deutsch' },
  { value: 'en', label: 'English' },
]

const BOOL_SETTINGS = [
  { name: 'show_empty_line', label: () => m.settings_show_empty_line(), initial: (c: AppConfig) => c.showEmptyLine },
  { name: 'suggest_time', label: () => m.settings_suggest_time(), initial: (c: AppConfig) => c.suggestTime },
  { name: 'show_future', label: () => m.settings_show_future(), initial: (c: AppConfig) => c.showFuture },
] as const

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

export default function Settings() {
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
      const body = await postForm('/settings/save', {
        locale,
        show_empty_line: data.get('show_empty_line') ? 1 : 0,
        suggest_time: data.get('suggest_time') ? 1 : 0,
        show_future: data.get('show_future') ? 1 : 0,
      })
      const result = JSON.parse(body) as SaveResponse
      if (!result.success) {
        setStatus({ kind: 'error', message: result.message || m.settings_save_error() })

        return
      }

      // All UI strings are locale-bound at load time; a locale change needs a
      // full reload, other settings apply on the next data fetch.
      if (result.locale !== config.locale) {
        window.location.reload()

        return
      }

      setStatus({ kind: 'ok' })
    } catch (error) {
      const message = apiErrorMessage(error, m.settings_save_error())
      setStatus({ kind: 'error', message })
    }
  }

  return (
    <section class="form-page">
      <h2 class="visually-hidden">{m.settings_title()}</h2>
      <form class="stack-form" onSubmit={(event) => void onSubmit(event)}>
        <label class="field">
          <span>{m.settings_language()}</span>
          <select name="locale">
            <For each={LANGUAGES}>
              {(lang) => (
                <option value={lang.value} selected={lang.value === config.locale}>
                  {lang.label}
                </option>
              )}
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
    </section>
  )
}
