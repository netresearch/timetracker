import { createSignal, For, Show } from 'solid-js'

import { SecuritySection } from '../components/SecuritySection'
import { WorklogImportSection } from '../components/WorklogImportSection'
import { WorklogSyncPreferences } from '../components/WorklogSyncPreferences'
import { apiErrorMessage, postForm } from '../api/client'
import { appConfig, type AppConfig } from '../config'
import { dateFormat, setDateFormat, formatWith, validatePattern, type DateFormatMode } from '../lib/dateFormat'
import { isoDate } from '../lib/format'
import { getFontFamily, setFontFamily, getFontSize, setFontSize, type FontFamily, type FontSize } from '../lib/fontPref'
import { getEnterBehavior, setEnterBehavior, type EnterBehavior } from '../lib/gridEditPref'
import { getNavLayout, setNavLayout, type NavLayout } from '../lib/navLayoutPref'
import { m } from '../paraglide/messages.js'

// Client-side-only UI preferences (localStorage, not the server settings).
const ENTER_BEHAVIORS: { value: EnterBehavior; label: () => string }[] = [
  { value: 'stay', label: () => m.settings_grid_enter_stay() },
  { value: 'down', label: () => m.settings_grid_enter_down() },
  { value: 'right', label: () => m.settings_grid_enter_right() },
]

const DATE_MODES: { value: DateFormatMode; label: () => string }[] = [
  { value: 'iso', label: () => m.settings_dateformat_iso() },
  { value: 'auto', label: () => m.settings_dateformat_auto() },
  { value: 'custom', label: () => m.settings_dateformat_custom() },
]

const FONT_FAMILIES: { value: FontFamily; label: () => string }[] = [
  { value: 'default', label: () => m.settings_font_default() },
  { value: 'system', label: () => m.settings_font_system() },
  { value: 'dyslexic', label: () => m.settings_font_dyslexic() },
]

const FONT_SIZES: { value: FontSize; label: () => string }[] = [
  { value: 'normal', label: () => m.settings_fontsize_normal() },
  { value: 'large', label: () => m.settings_fontsize_large() },
  { value: 'larger', label: () => m.settings_fontsize_larger() },
]

const NAV_LAYOUTS: { value: NavLayout; label: () => string }[] = [
  { value: 'top', label: () => m.settings_layout_top() },
  { value: 'side', label: () => m.settings_layout_side() },
  { value: 'side-right', label: () => m.settings_layout_side_right() },
]

interface SaveResponse {
  success: boolean
  locale: string
  message: string
}

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
  name: string
  label: () => string
  help?: () => string
  initial: (c: AppConfig) => boolean
}

const BOOL_SETTINGS: BoolSetting[] = [
  { name: 'show_empty_line', label: () => m.settings_show_empty_line(), initial: (c) => c.showEmptyLine },
  { name: 'suggest_time', label: () => m.settings_suggest_time(), initial: (c) => c.suggestTime },
  { name: 'show_future', label: () => m.settings_show_future(), initial: (c) => c.showFuture },
  {
    name: 'personio_sync_enabled',
    label: () => m.settings_personio_sync(),
    help: () => m.settings_personio_sync_help(),
    initial: (c) => c.personioSyncEnabled,
  },
]

type Status = { kind: 'idle' | 'saving' } | { kind: 'ok' } | { kind: 'error'; message: string }

export default function Settings() {
  const config = appConfig()
  const [enterPref, setEnterPref] = createSignal<EnterBehavior>(getEnterBehavior())
  // Typography preferences (client-side; apply instantly to <html>).
  const [fontFamily, setFontFamilySig] = createSignal<FontFamily>(getFontFamily())
  const [fontSize, setFontSizeSig] = createSignal<FontSize>(getFontSize())
  // Navigation-layout preference (client-side; applies instantly to <html>).
  const [navLayout, setNavLayoutSig] = createSignal<NavLayout>(getNavLayout())
  // Date-format preference (client-side; applies instantly to open grids).
  const [dfMode, setDfMode] = createSignal<DateFormatMode>(dateFormat().mode)
  const [dfPattern, setDfPattern] = createSignal(dateFormat().pattern)
  const sampleIso = isoDate(new Date())
  const dfInvalid = (): boolean => dfMode() === 'custom' && !validatePattern(dfPattern()).ok
  const dfPreview = (): string => formatWith(sampleIso, { mode: dfMode(), pattern: dfPattern() })
  // Persist on every change, but only when valid (an invalid custom pattern keeps
  // the last good one rather than blanking dates).
  const commitDateFormat = (): void => {
    const mode = dfMode()
    if (mode !== 'custom' || validatePattern(dfPattern()).ok) {
      setDateFormat({ mode, pattern: dfPattern() })
    }
  }
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
        personio_sync_enabled: data.get('personio_sync_enabled') ? 1 : 0,
        min_entry_duration: Number(data.get('min_entry_duration') ?? config.minEntryDuration),
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
    <section class="form-page settings-page">
      {/* Account settings — persisted server-side, applied on every device.
          Only this card has (and needs) the Save button. */}
      <form class="stack-form" onSubmit={(event) => void onSubmit(event)}>
        <fieldset class="settings-group">
          <legend>{m.settings_section_account()}</legend>
          <p class="settings-section-hint">{m.settings_section_account_hint()}</p>

          <label class="field">
            <span>{m.settings_language()}</span>
            <select name="locale" value={config.locale}>
              <For each={LANGUAGES}>
                {(lang) => (
                  <option value={lang.value}>
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
                <Show when={setting.help}>
                  <small class="field-hint">{setting.help?.()}</small>
                </Show>
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

      {/* Account security — password change + two-factor. Separate per-account
          server operations, so each posts on its own (not via /settings/save). */}
      <SecuritySection />

      {/* Self-service Jira worklog import (ADR-023 use case 1) — a separate
          per-account operation that drives the v2 worklog-sync endpoints. */}
      <WorklogImportSection />

      {/* Per-user sync opt-in (ADR-023 amendment): the caller opts their own
          worklogs into the nightly sync; a PO can opt into sync-all. */}
      <WorklogSyncPreferences />

      {/* Device-local UI preferences — localStorage only, apply instantly.
          Deliberately OUTSIDE the save form: nothing here is submitted. The
          wrapper is a div (not a sectioning element): the accessible group
          name comes from the fieldset's legend. */}
      <div class="stack-form">
        <fieldset class="settings-group">
          <legend>{m.settings_section_device()}</legend>
          <p class="settings-section-hint">{m.settings_section_device_hint()}</p>

          <label class="field">
            <span>{m.settings_grid_enter()}</span>
            <select
              value={enterPref()}
              onChange={(event) => {
                const next = event.currentTarget.value as EnterBehavior
                setEnterPref(next)
                setEnterBehavior(next)
              }}
            >
              <For each={ENTER_BEHAVIORS}>
                {(option) => <option value={option.value}>{option.label()}</option>}
              </For>
            </select>
            <small class="field-hint">{m.settings_grid_enter_hint()}</small>
          </label>

          {/* Display-only: the wire format and the inline date editor stay ISO. */}
          <label class="field">
            <span>{m.settings_dateformat()}</span>
            <select
              value={dfMode()}
              onChange={(event) => { setDfMode(event.currentTarget.value as DateFormatMode); commitDateFormat() }}
            >
              <For each={DATE_MODES}>
                {(option) => <option value={option.value}>{option.label()}</option>}
              </For>
            </select>
            <Show when={dfMode() === 'custom'}>
              <input
                type="text"
                name="date_pattern"
                maxLength={32}
                value={dfPattern()}
                aria-label={m.settings_dateformat_pattern()}
                aria-invalid={dfInvalid() ? 'true' : undefined}
                onInput={(event) => { setDfPattern(event.currentTarget.value); commitDateFormat() }}
              />
              <small class="field-hint">{m.settings_dateformat_pattern_hint()}</small>
            </Show>
            <small class="field-hint" aria-live="polite">
              <Show when={!dfInvalid()} fallback={m.settings_dateformat_invalid()}>
                {m.settings_dateformat_preview()}: {dfPreview()}
              </Show>
            </small>
          </label>

          {/* Typography preferences apply instantly to <html> (ADR-014).
              Headings keep the brand display face. */}
          <label class="field">
            <span>{m.settings_font()}</span>
            <select
              value={fontFamily()}
              onChange={(event) => {
                const next = event.currentTarget.value as FontFamily
                setFontFamilySig(next)
                setFontFamily(next)
              }}
            >
              <For each={FONT_FAMILIES}>
                {(option) => <option value={option.value}>{option.label()}</option>}
              </For>
            </select>
            <small class="field-hint">{m.settings_font_hint()}</small>
          </label>

          <label class="field">
            <span>{m.settings_fontsize()}</span>
            <select
              value={fontSize()}
              onChange={(event) => {
                const next = event.currentTarget.value as FontSize
                setFontSizeSig(next)
                setFontSize(next)
              }}
            >
              <For each={FONT_SIZES}>
                {(option) => <option value={option.value}>{option.label()}</option>}
              </For>
            </select>
          </label>

          {/* Switches the header between the top bar and a left/right sidebar
              without a reload. */}
          <label class="field">
            <span>{m.settings_layout()}</span>
            <select
              value={navLayout()}
              onChange={(event) => {
                const next = event.currentTarget.value as NavLayout
                setNavLayoutSig(next)
                setNavLayout(next)
              }}
            >
              <For each={NAV_LAYOUTS}>
                {(option) => <option value={option.value}>{option.label()}</option>}
              </For>
            </select>
            <small class="field-hint">{m.settings_layout_hint()}</small>
          </label>
        </fieldset>
      </div>
    </section>
  )
}
