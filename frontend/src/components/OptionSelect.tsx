import type { JSX } from 'solid-js'

import type { NamedOption } from '../api/queries'
import { SearchableSelect } from './SearchableSelect'

/**
 * A labelled relation picker with a leading "all" (value 0) option, shared by the
 * Auswertung filter bar and the Billing form. Built on {@link SearchableSelect}
 * so the (often long) option list is type-to-search; the props stay a plain
 * single-select contract (value / onInput) for its callers.
 */
export function OptionSelect(props: {
  label: string
  value: number
  onInput: (value: number) => void
  options: NamedOption[] | undefined
  allLabel: string
  disabled?: boolean
}): JSX.Element {
  return (
    <SearchableSelect
      label={props.label}
      value={props.value}
      // Single-select only ever emits a scalar; guard keeps the numeric contract.
      onChange={(value) => props.onInput(typeof value === 'number' ? value : (value[0] ?? 0))}
      options={props.options}
      allLabel={props.allLabel}
      disabled={props.disabled}
    />
  )
}
