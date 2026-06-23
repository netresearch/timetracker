import { createEffect, createMemo, createSignal } from 'solid-js'

import { dateFormatPlaceholder, formatUserDate, parseUserDate } from '../lib/dateFormat'

interface DateFieldProps {
  /** ISO yyyy-mm-dd, or '' for no date. */
  value: string
  /** Called with a validated ISO yyyy-mm-dd (or '' when cleared). */
  onChange: (iso: string) => void
  id?: string
  required?: boolean
  disabled?: boolean
}

/**
 * A date input that displays AND accepts the user's configured date format
 * ({@link dateFormat}), with ISO yyyy-mm-dd always accepted as a fallback, and
 * commits a validated ISO value via onChange. It is a plain text input — not a
 * native `<input type="date">`, whose display the browser owns and cannot render
 * in the configured format — mirroring the inline-grid date editor so forms and
 * grid stay consistent.
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

  // Re-sync the shown text when the resting value/format changes from outside —
  // but never while the user is typing in this field.
  createEffect(() => {
    const next = formatted()
    if (document.activeElement !== inputRef) {
      setText(next)
      setInvalid(false)
    }
  })

  const commit = (): void => {
    const iso = parseUserDate(text())
    if (iso === null) {
      setInvalid(true)
      return
    }
    setInvalid(false)
    props.onChange(iso)
    setText(formatIso(iso))
  }

  return (
    <input
      ref={(el) => {
        inputRef = el
      }}
      id={props.id}
      type="text"
      class="date-field"
      inputmode="numeric"
      autocomplete="off"
      required={props.required}
      disabled={props.disabled}
      aria-invalid={invalid() ? 'true' : undefined}
      placeholder={dateFormatPlaceholder()}
      value={text()}
      onInput={(e) => {
        setText(e.currentTarget.value)
        setInvalid(false)
      }}
      onChange={commit}
      onBlur={commit}
    />
  )
}
