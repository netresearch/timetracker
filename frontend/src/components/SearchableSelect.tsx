import { Combobox } from '@ark-ui/solid/combobox'
import { createMemo, createSignal, For, type JSX, Show } from 'solid-js'

import type { NamedOption } from '../api/queries'
import { ComboboxContent, comboCollection, type ComboItem } from '../lib/comboboxParts'
import { m } from '../paraglide/messages.js'

// The "all / none" pseudo-option (value 0) for an optional single select. A
// sentinel that can't collide with a real relation id (which stringify to
// digits), so it never enters the committed numeric value.
const ALL_VALUE = '__all__'

/**
 * A searchable relation combobox for ordinary FORMS (not the admin grid).
 *
 * Single and multi share ONE layout so they read the same (a maintainer request):
 * the editable input IS the search field (WAI-ARIA 1.2 combobox), the chosen
 * value(s) sit as chips beside it, and a trailing ▾ opens the list. Single differs
 * only in that its chip is not removable (pick another option, or the "all" entry,
 * to change it) and selecting closes the popup. Opening always shows ALL options —
 * the current selection highlights, it never pre-filters the list.
 *
 * Boundary: Ark deals in string[]; relation ids are carried verbatim as the
 * option's string value and converted back to numbers only in onChange.
 */
export function SearchableSelect(props: {
  label: string
  /** Single: the chosen id (0 = none/all). Multi: the chosen ids. */
  value: number | number[]
  onChange: (value: number | number[]) => void
  /** Option source in the {@link NamedOption} shape ({ id, label }). */
  options: NamedOption[] | undefined
  multiple?: boolean
  /** Single-only: label for the "all / none" (value 0) entry. */
  allLabel?: string
  disabled?: boolean
  required?: boolean
}): JSX.Element {
  let inputEl: HTMLInputElement | undefined
  const [inputValue, setInputValue] = createSignal('')

  const isMulti = (): boolean => props.multiple === true

  // Controlled selection, derived from props.value (never carries the ALL
  // sentinel — value 0 maps to an empty selection).
  const selectedValues = createMemo<string[]>(() => {
    const raw = props.value
    if (Array.isArray(raw)) {
      return raw.filter((value) => value > 0).map(String)
    }

    return raw > 0 ? [String(raw)] : []
  })

  const allItems = createMemo<ComboItem[]>(() => (props.options ?? []).map((option) => ({ value: String(option.id), label: option.label })))
  const labelOf = (value: string): string =>
    value === ALL_VALUE ? (props.allLabel ?? '') : (allItems().find((item) => item.value === value)?.label ?? value)

  // Offer an explicit "all / none" entry only for an optional single select.
  const showAll = (): boolean => !isMulti() && props.allLabel !== undefined
  const filteredItems = createMemo<ComboItem[]>(() => {
    const query = inputValue().trim().toLowerCase()
    const base = query === '' ? allItems() : allItems().filter((item) => item.label.toLowerCase().includes(query))

    return showAll() && query === '' ? [{ value: ALL_VALUE, label: props.allLabel ?? '' }, ...base] : base
  })
  const collection = createMemo(() => comboCollection(filteredItems()))

  const removeValue = (value: string): void => {
    props.onChange(selectedValues().filter((current) => current !== value).map(Number))
  }

  // The placeholder is only an invitation to search — shown while nothing is chosen.
  // Once a value is picked its chip carries the display, so the input reads as a
  // bare search box.
  const placeholder = (): string => {
    if (selectedValues().length > 0) {
      return ''
    }

    return isMulti() ? m.app_type_to_add() : m.app_type_to_search()
  }

  return (
    <div class="field">
      <span>{props.label}</span>
      <div class={`searchable-select chip-select${isMulti() ? ' inline-tags' : ''}${props.disabled === true ? ' is-disabled' : ''}`}>
        {/* Announce selection changes — Ark's own live region only narrates the
            highlighted option during navigation. */}
        <span class="visually-hidden" role="status" aria-live="polite">
          {selectedValues().map(labelOf).join(', ')}
        </span>

        {/* The chosen value(s) as chips — multi: removable; single: one, non-removable.
            Outside Root so they share the field's wrapping flex row with the
            (display:contents) input + ▾. */}
        <ul class="tag-list">
          <For each={selectedValues()}>
            {(value) => (
              <li class="tag">
                <span class="tag-label">{labelOf(value)}</span>
                <Show when={isMulti()}>
                  <button
                    type="button"
                    class="tag-remove"
                    tabindex="-1"
                    disabled={props.disabled}
                    aria-label={`${m.admin_delete()}: ${labelOf(value)}`}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => { removeValue(value); inputEl?.focus() }}
                  >×</button>
                </Show>
              </li>
            )}
          </For>
        </ul>

        <Combobox.Root
          class="chip-select-root"
          collection={collection()}
          multiple={isMulti()}
          disabled={props.disabled}
          value={selectedValues()}
          onValueChange={(details) => {
            if (isMulti()) {
              props.onChange(details.value.map(Number))

              return
            }
            const first = details.value[0]
            props.onChange(first === undefined || first === ALL_VALUE ? 0 : Number(first))
          }}
          inputValue={inputValue()}
          onInputValueChange={(details) => {
            // Ark echoes a single-select's chosen label back into the input; suppress that
            // so the input stays a bare (empty) search box and the value shows only as its
            // chip. That also means reopening never pre-filters by the current selection
            // (WAI-ARIA APG: the selection highlights, it does not filter). A real query —
            // which differs from the chosen label — passes through untouched, so typing
            // (even the keystroke that opens the list) filters normally.
            const isSelectionEcho = !isMulti() && selectedValues().some((value) => labelOf(value) === details.inputValue)
            setInputValue(isSelectionEcho ? '' : details.inputValue)
          }}
          onOpenChange={(details) => {
            if (details.open) {
              requestAnimationFrame(() => { if (inputEl?.isConnected) inputEl.focus() })
            } else {
              // Drop a typed query on close so the next open starts fresh with all options.
              setInputValue('')
            }
          }}
          openOnClick
          closeOnSelect={!isMulti()}
          allowCustomValue={false}
          inputBehavior="autohighlight"
          positioning={{ strategy: 'fixed', sameWidth: true, gutter: 2, flip: true, fitViewport: true }}
        >
          <Combobox.Label class="visually-hidden">{props.label}</Combobox.Label>
          <Combobox.Control class="combobox-control">
            <Combobox.Input
              ref={(element) => { inputEl = element }}
              class="combobox-input"
              aria-label={props.label}
              aria-required={props.required === true ? 'true' : undefined}
              placeholder={placeholder()}
            />
            <Combobox.Trigger class="combobox-trigger" tabindex="-1" aria-label={props.label}>▾</Combobox.Trigger>
          </Combobox.Control>
          {/* Rendered inline (NOT body-portalled) with a fixed positioning strategy. The
              popup stays in this field's DOM subtree, so when the field sits in a modal
              dialog its focus trap can't inert the popup (a body-portalled popup is a
              sibling of the dialog → inert → dead, the Billing-modal bug); `fixed` still
              lets it break out of any card/table `overflow` and clamp to the viewport. */}
          <Combobox.Positioner class="combobox-positioner">
            <ComboboxContent items={filteredItems()} />
          </Combobox.Positioner>
        </Combobox.Root>
      </div>
    </div>
  )
}
