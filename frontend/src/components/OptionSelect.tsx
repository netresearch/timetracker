import { For } from 'solid-js'

import type { NamedOption } from '../api/queries'

/**
 * A labelled relation `<select>` with a leading "all" (value 0) option, shared
 * by the Auswertung filter bar and the Billing form.
 */
export function OptionSelect(props: {
  label: string
  value: number
  onInput: (value: number) => void
  options: NamedOption[] | undefined
  allLabel: string
  disabled?: boolean
}) {
  return (
    <label class="field">
      <span>{props.label}</span>
      <select
        value={props.value}
        disabled={props.disabled}
        onInput={(event) => props.onInput(Number(event.currentTarget.value))}
      >
        <option value="0">{props.allLabel}</option>
        <For each={props.options ?? []}>
          {(option) => <option value={option.id}>{option.label}</option>}
        </For>
      </select>
    </label>
  )
}
