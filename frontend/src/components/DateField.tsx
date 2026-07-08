import { createEffect, createMemo, createSignal, Show } from 'solid-js'

import { dateFormatPlaceholder, formatUserDate, parseUserDate } from '../lib/dateFormat'
import { dateGhost, parseDateInput } from '../lib/dateInput'
import { isoDate } from '../lib/format'
import { DatePopover } from './DatePopover'

interface DateFieldProps {
  /** ISO yyyy-mm-dd, or '' for no date. */
  value: string
  /** Called with a validated ISO yyyy-mm-dd (or '' when cleared). */
  onChange: (iso: string) => void
  id?: string
  required?: boolean
  disabled?: boolean
  /** Opt-in: show a calendar trigger that opens a body-portalled month picker. */
  calendar?: boolean
  /**
   * Opt-in: "type-the-day" completion. Partial input (e.g. "7") completes to a
   * full date from today, a grey ghost previews the completion while typing, and
   * an empty field commits to today. Off by default so existing callers are
   * unchanged.
   */
  autocomplete?: boolean
}

/**
 * A date input that displays AND accepts the user's configured date format
 * ({@link dateFormat}), with ISO yyyy-mm-dd always accepted as a fallback, and
 * commits a validated ISO value via onChange. It is a plain text input — not a
 * native `<input type="date">`, whose display the browser owns and cannot render
 * in the configured format — mirroring the inline-grid date editor so forms and
 * grid stay consistent.
 *
 * The `calendar` and `autocomplete` props are opt-in enhancements (both default
 * off); with neither set the behavior is byte-for-byte the plain text input.
 */
export function DateField(props: DateFieldProps) {
  let inputRef: HTMLInputElement | undefined
  const formatIso = (iso: string): string => (iso === '' ? '' : formatUserDate(iso))
  // Tracks props.value AND the date-format pref (via formatUserDate), so the
  // resting display reformats live when either changes.
  const formatted = createMemo(() => (props.value === '' ? '' : formatUserDate(props.value)))
  // eslint-disable-next-line solid/reactivity -- one-time initial seed; the effect below keeps text in sync
  const [text, setText] = createSignal(formatted())
  const [invalid, setInvalid] = createSignal(false)
  const [open, setOpen] = createSignal(false)

  const enhanced = (): boolean => props.calendar === true || props.autocomplete === true
  const today = (): string => isoDate(new Date())

  // Re-sync the shown text when the resting value/format changes from outside —
  // but never while the user is typing in this field.
  createEffect(() => {
    const next = formatted()
    if (document.activeElement !== inputRef) {
      setText(next)
      setInvalid(false)
    }
  })

  // Grey ghost of the completed date while typing a partial value — only when it
  // differs from what's typed (so a fully-typed date shows no redundant echo).
  // Reads the active date-format pref (matching the input's display format).
  const ghost = createMemo(() => (props.autocomplete === true ? dateGhost(text(), today()) : ''))

  const applyIso = (iso: string): void => {
    setInvalid(false)
    if (iso !== props.value) {
      props.onChange(iso)
    }
    setText(formatIso(iso))
  }

  const commit = (): void => {
    const raw = text()
    // Strict parse first (active display format or ISO). With autocomplete on,
    // fall back to type-the-day completion, which also maps an empty field to
    // today — so partial input like "7" completes and blank commits today.
    let iso = parseUserDate(raw)
    if (props.autocomplete === true && (iso === null || raw.trim() === '')) {
      iso = parseDateInput(raw, today())
    }
    if (iso === null) {
      setInvalid(true)

      return
    }
    applyIso(iso)
  }

  const pickFromCalendar = (iso: string): void => {
    applyIso(iso)
    setOpen(false)
  }

  const input = (
    <input
      ref={(el) => {
        inputRef = el
      }}
      id={props.id}
      type="text"
      class="date-field"
      autocomplete="off"
      required={props.required}
      disabled={props.disabled}
      aria-invalid={invalid() ? 'true' : undefined}
      placeholder={dateFormatPlaceholder()}
      // Width follows the content so custom date patterns (up to 32 chars) are
      // never clipped; the CSS min-width keeps ISO/DE compact.
      size={Math.max(dateFormatPlaceholder().length, text().length, 10)}
      value={text()}
      onInput={(e) => {
        setText(e.currentTarget.value)
        setInvalid(false)
      }}
      onChange={commit}
    />
  )

  return (
    <Show when={enhanced()} fallback={input}>
      <span class="date-field-wrap">
        {input}
        <Show when={ghost() !== ''}>
          <span class="date-field-ghost" aria-hidden="true">{ghost()}</span>
        </Show>
        <Show when={props.calendar === true}>
          <DatePopover
            open={open()}
            onOpenChange={setOpen}
            triggerClass="date-field-trigger"
            disabled={props.disabled}
            value={props.value}
            todayIso={today()}
            onSelect={pickFromCalendar}
          />
        </Show>
      </span>
      <span class="field-hint">{dateFormatPlaceholder()}</span>
    </Show>
  )
}
