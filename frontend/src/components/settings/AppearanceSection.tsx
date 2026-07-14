import { createSignal, For, Show } from 'solid-js'

import { dateFormat, setDateFormat, formatWith, validatePattern, type DateFormatMode } from '../../lib/dateFormat'
import { isoDate } from '../../lib/format'
import { getFontFamily, setFontFamily, getFontSize, setFontSize, type FontFamily, type FontSize } from '../../lib/fontPref'
import { getEnterBehavior, setEnterBehavior, type EnterBehavior } from '../../lib/gridEditPref'
import { getNavLayout, setNavLayout, type NavLayout } from '../../lib/navLayoutPref'
import { m } from '../../paraglide/messages.js'

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

/** Device-local UI preferences — localStorage only, apply instantly.
 *  Nothing here is submitted; there is deliberately no Save button. */
export function AppearanceSection() {
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

  return (
    /* Device-local UI preferences — localStorage only, apply instantly.
       Deliberately OUTSIDE the save form: nothing here is submitted. The
       wrapper is a div (not a sectioning element): the accessible group
       name comes from the fieldset's legend. */
    <div class="stack-form">
      {/* One h2 per settings section so the page outline is h1 → h2.
          The h2 lives INSIDE the legend: a single text node serves both the
          outline and the group name, so screen readers don't announce the
          title twice (hidden heading + identically-named legend). */}
      <fieldset class="settings-group">
        <legend><h2>{m.settings_nav_appearance()}</h2></legend>
        <p class="settings-section-hint settings-instant-badge">{m.settings_section_device_hint()}</p>

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
  )
}
