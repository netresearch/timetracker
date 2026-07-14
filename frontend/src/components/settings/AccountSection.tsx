import { createEffect, createResource, createSignal, For, Show } from 'solid-js'

import { apiErrorMessage } from '../../api/client'
import { fetchSettings, patchSettings, type UserSettings } from '../../api/settings'
import { appConfig } from '../../config'
import { HelpPopover } from '../HelpPopover'
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
}

const BOOL_SETTINGS: BoolSetting[] = [
  { name: 'show_empty_line', label: () => m.settings_show_empty_line() },
  { name: 'suggest_time', label: () => m.settings_suggest_time() },
  { name: 'show_future', label: () => m.settings_show_future() },
]

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

/** Account settings — persisted server-side (PATCH /api/v2/settings),
 *  applied on every device. The only settings section with a Save button. */
export function AccountSection() {
  const config = appConfig()
  // Hydrate from the server on mount: the page-load APP_CONFIG snapshot can be
  // stale after a save (sections remount lazily, so switching away and back
  // would otherwise show pre-save values). APP_CONFIG is the pre-fetch (and
  // on-error) default; `.latest` stays undefined until GET /api/v2/settings
  // resolves, so a failed fetch degrades to the snapshot instead of throwing.
  const [remote] = createResource(fetchSettings)
  // The form is uncontrolled (submit reads FormData); `values` only seeds each
  // input's initial DOM value. Apply the fetched settings ONLY until the user
  // touches a field, so a GET that resolves mid-edit can't overwrite an
  // in-progress selection (mirrors PersonioOptIn's idle guard — here the analog
  // of "not idle" is "the user has interacted", since editing this form doesn't
  // change status until Save).
  const [values, setValues] = createSignal<Omit<UserSettings, 'personio_sync_enabled'>>({
    locale: config.locale,
    show_empty_line: config.showEmptyLine,
    suggest_time: config.suggestTime,
    show_future: config.showFuture,
    min_entry_duration: config.minEntryDuration,
  })
  const [touched, setTouched] = createSignal(false)
  createEffect(() => {
    const s = remote.latest
    if (s !== undefined && !touched()) {
      setValues({
        locale: s.locale,
        show_empty_line: s.show_empty_line,
        suggest_time: s.suggest_time,
        show_future: s.show_future,
        min_entry_duration: s.min_entry_duration,
      })
    }
  })
  const [status, setStatus] = createSignal<Status>({ kind: 'idle' })
  const statusMessage = () => {
    const current = status()

    return current.kind === 'error' ? current.message : ''
  }

  async function onSubmit(event: SubmitEvent) {
    event.preventDefault()
    const form = event.currentTarget as HTMLFormElement
    const data = new FormData(form)
    const rawLocale = data.get('locale')
    const locale = typeof rawLocale === 'string' ? rawLocale : config.locale

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
    <form class="stack-form" onInput={() => setTouched(true)} onSubmit={(event) => void onSubmit(event)}>
      {/* One h2 per settings section so the page outline is h1 → h2 (→ h3).
          The h2 lives INSIDE the legend: a single text node serves both the
          outline and the group name, so screen readers don't announce the
          title twice (hidden heading + identically-named legend). */}
      <fieldset class="settings-group">
        <legend><h2>{m.settings_section_account()}</h2></legend>
        <p class="settings-section-hint">{m.settings_section_account_hint()}</p>

        <label class="field">
          <span>{m.settings_language()}</span>
          <select name="locale" value={values().locale}>
            <For each={LANGUAGES}>
              {(lang) => <option value={lang.value}>{lang.label}</option>}
            </For>
          </select>
        </label>

        <For each={BOOL_SETTINGS}>
          {(setting) => (
            <label class="field-check">
              <input type="checkbox" name={setting.name} checked={values()[setting.name]} />
              <span>{setting.label()}</span>
            </label>
          )}
        </For>

        {/* Server setting: the minimum span of a suggested entry — for today's
            entries the end pre-fills to max(now, start + this many minutes),
            for other days to start + N (#588).
            A <div class="field"> (not a wrapping <label>) so the help trigger sits
            beside — not inside — the label and can't focus the input on click. */}
        <div class="field">
          <span>
            <label for="settings-min-entry-duration">{m.settings_min_entry_duration()}</label>
            <HelpPopover topic={m.settings_min_entry_duration()}>{m.settings_help_min_duration()}</HelpPopover>
          </span>
          <input id="settings-min-entry-duration" type="number" name="min_entry_duration" min="0" max="1440" step="5" value={values().min_entry_duration} />
          <small class="field-hint">{m.settings_min_entry_duration_hint()}</small>
        </div>
      </fieldset>

      <div class="form-actions">
        <button type="submit" class="primary-button" disabled={status().kind === 'saving'}>
          {status().kind === 'saving' ? m.app_saving() : m.app_save()}
        </button>
        <Show when={status().kind === 'ok'}>
          <output class="form-status is-ok">{m.settings_saved()}</output>
        </Show>
        <Show when={status().kind === 'error'}>
          <span role="alert" class="form-status is-error">{statusMessage()}</span>
        </Show>
      </div>
    </form>
  )
}
