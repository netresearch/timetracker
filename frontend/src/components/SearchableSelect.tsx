import { Combobox } from '@ark-ui/solid/combobox'
import { createEffect, createMemo, createSignal, For, type JSX } from 'solid-js'

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
 * Single and multi read differently on purpose:
 *   - SINGLE displays the chosen option's label IN the editable input (a
 *     React-Select single control): the input IS both the value display and the
 *     search field. Opening lists ALL options (the selection highlights, it does
 *     NOT pre-filter); typing filters; picking commits and closes; closing without
 *     a pick reverts the input to the selected label.
 *   - MULTI keeps the chosen values as removable chips beside a bare inline search
 *     input, with a trailing ▾ that opens the (always complete) list.
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
  // The input's text, controlled but driven BY Ark: on selection Ark echoes the
  // chosen label into it (so a single select shows its value IN the input), while
  // typing writes the query. A single select also seeds/reverts it to the selected
  // label via the effect below (Ark echoes only on a selection event, not at mount).
  const [inputText, setInputText] = createSignal('')
  // Single-only filter gate: true while the user is actively typing a query. Set on
  // real input (native onInput), reset on close — so opening lists ALL options (the
  // resting label never pre-filters) and a typed-but-not-committed query never persists.
  const [searching, setSearching] = createSignal(false)

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

  // Single: the label the input DISPLAYS when not searching — the chosen option's
  // label, or EMPTY when nothing is chosen (value 0). The empty resting state (the
  // search placeholder shows through) matches the existing callers' "all = blank
  // field"; the "all / none" entry still lives in the dropdown to clear a selection.
  const selectedLabel = createMemo<string>(() => {
    if (isMulti()) {
      return ''
    }
    const values = selectedValues()

    return values.length > 0 ? labelOf(values[0]!) : ''
  })

  // Single: keep the input showing the selected label whenever the user is NOT typing
  // — at mount, on external value changes, and (searching flips false) on close, which
  // reverts a typed-but-not-committed query. Skipped while searching so typing shows.
  createEffect(() => {
    if (!isMulti() && !searching()) {
      setInputText(selectedLabel())
    }
  })

  // What the list filters by. A single select only filters once the user is really
  // typing, so opening shows ALL options (the selection highlights, never pre-filters).
  const activeQuery = createMemo<string>(() => (isMulti() || searching() ? inputText() : ''))

  const filteredItems = createMemo<ComboItem[]>(() => {
    const q = activeQuery().trim().toLowerCase()
    const base = q === '' ? allItems() : allItems().filter((item) => item.label.toLowerCase().includes(q))

    return showAll() && q === '' ? [{ value: ALL_VALUE, label: props.allLabel ?? '' }, ...base] : base
  })
  const collection = createMemo(() => comboCollection(filteredItems()))

  const removeValue = (value: string): void => {
    props.onChange(selectedValues().filter((current) => current !== value).map(Number))
  }

  const placeholder = (): string => {
    if (isMulti()) {
      return selectedValues().length > 0 ? '' : m.app_type_to_add()
    }

    // Single: the input carries its value, so this hint only shows through when that
    // value is empty (nothing chosen and no "all" label, or the query is cleared).
    return m.app_type_to_search()
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

        {/* MULTI only: the chosen values as removable chips. A single select shows its
            value IN the input (below), so it renders no chip. Outside Root so the chips
            share the field's wrapping flex row with the (display:contents) input + ▾. */}
        <ul class="tag-list">
          <For each={isMulti() ? selectedValues() : []}>
            {(value) => (
              <li class="tag">
                <span class="tag-label">{labelOf(value)}</span>
                <button
                  type="button"
                  class="tag-remove"
                  tabindex="-1"
                  disabled={props.disabled}
                  aria-label={`${m.admin_delete()}: ${labelOf(value)}`}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => { removeValue(value); inputEl?.focus() }}
                >×</button>
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
          inputValue={inputText()}
          onInputValueChange={(details) => {
            // Ark owns the input text: typing writes the query, and on selection it
            // echoes the chosen label back in (which is what a single select displays).
            // The `searching` flag (set on real typing) decides whether that text
            // filters — so a resting single label never pre-filters the list.
            setInputText(details.inputValue)
          }}
          onOpenChange={(details) => {
            if (details.open) {
              requestAnimationFrame(() => {
                if (!inputEl?.isConnected) {
                  return
                }
                inputEl.focus()
                // Opened by click (not by typing): select the shown label so the first
                // keystroke replaces it.
                if (!isMulti() && !searching()) {
                  inputEl.select()
                }
              })
            } else {
              // Closing without committing: end search mode. For a single select the
              // effect then reverts the input to the selected label; multi's search box
              // clears.
              setSearching(false)
              if (isMulti()) {
                setInputText('')
              }
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
              onInput={() => {
                // Fires only on REAL user typing (not Ark's programmatic writes). Single:
                // enter search mode so the typed text (captured by onInputValueChange)
                // starts filtering the list. Multi always filters, so it is a no-op there.
                if (!isMulti()) {
                  setSearching(true)
                }
              }}
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
