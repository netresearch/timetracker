import { Combobox, createListCollection } from '@ark-ui/solid/combobox'
import { createMemo, createSignal, For, type JSX, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import type { NamedOption } from '../api/queries'
import { m } from '../paraglide/messages.js'

type Item = { value: string; label: string }

// The "all / none" pseudo-option (value 0) for an optional single select. A
// sentinel that can't collide with a real relation id (which stringify to
// digits), so it never enters the committed numeric value.
const ALL_VALUE = '__all__'

/**
 * A searchable relation combobox for ordinary FORMS (not the admin grid),
 * built on the same Ark UI Combobox primitive + combobox CSS as the inline-grid
 * editor ({@link ChipSelect}), so it looks identical to the worklog table's cell
 * editor — but with a plain controlled form API (value / onChange) instead of
 * the grid's field/commit/cancel contract.
 *
 * Two layouts share all the logic:
 * - SINGLE: a compact control showing the chosen label + a ▾; the search field
 *   lives at the top of the (body-portalled) popup. An optional "all" (value 0)
 *   entry clears the selection.
 * - MULTI: removable chips + a leading magnifier + the search input, all in the
 *   control; the popup lists the options.
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

  const allItems = createMemo<Item[]>(() => (props.options ?? []).map((option) => ({ value: String(option.id), label: option.label })))
  const labelOf = (value: string): string =>
    value === ALL_VALUE ? (props.allLabel ?? '') : (allItems().find((item) => item.value === value)?.label ?? value)

  // Offer an explicit "all / none" entry only for an optional single select.
  const showAll = (): boolean => !isMulti() && props.allLabel !== undefined
  const filteredItems = createMemo<Item[]>(() => {
    const query = inputValue().trim().toLowerCase()
    const base = query === '' ? allItems() : allItems().filter((item) => item.label.toLowerCase().includes(query))

    return showAll() && query === '' ? [{ value: ALL_VALUE, label: props.allLabel ?? '' }, ...base] : base
  })
  const collection = createMemo(() => createListCollection<Item>({ items: filteredItems(), itemToValue: (item) => item.value, itemToString: (item) => item.label }))

  // Single-select control display: the chosen label, or the "all" label when
  // nothing is chosen (value 0), falling back to a generic "none" placeholder.
  const singleDisplay = (): string => {
    const first = selectedValues()[0]
    if (first !== undefined) {
      return labelOf(first)
    }

    return props.allLabel ?? m.app_select_none()
  }

  const removeValue = (value: string): void => {
    props.onChange(selectedValues().filter((current) => current !== value).map(Number))
  }

  const magnifier = (): JSX.Element => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
      <circle cx="10.5" cy="10.5" r="6.5" />
      <line x1="15.6" y1="15.6" x2="21" y2="21" />
    </svg>
  )

  const searchInput = (): JSX.Element => (
    <Combobox.Input
      ref={(element) => { inputEl = element }}
      class="combobox-input"
      aria-label={props.label}
      placeholder={isMulti() && selectedValues().length === 0 ? m.app_type_to_add() : m.app_type_to_search()}
    />
  )

  return (
    <div class="field">
      <span>{props.label}</span>
      <div class={`searchable-select chip-select${isMulti() ? ' inline-tags' : ''}${props.disabled === true ? ' is-disabled' : ''}`}>
        {/* Multi: a leading magnifier in the control. Single's search is in the popup. */}
        <Show when={isMulti()}>
          <span class="combobox-search" aria-hidden="true">{magnifier()}</span>
          <ul class="tag-list">
            <For each={selectedValues()}>
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
        </Show>

        {/* Announce selection changes — Ark's own live region only narrates the
            highlighted option during navigation. */}
        <span class="visually-hidden" role="status" aria-live="polite">
          {selectedValues().map(labelOf).join(', ')}
        </span>

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
          onInputValueChange={(details) => setInputValue(details.inputValue)}
          onOpenChange={(details) => {
            // Single's search input lives in the popup — focus it once the popup
            // mounts so typing starts immediately. Clear a stale filter on close.
            if (details.open) {
              requestAnimationFrame(() => { if (inputEl?.isConnected) inputEl.focus() })
            } else {
              setInputValue('')
            }
          }}
          openOnClick
          closeOnSelect={!isMulti()}
          allowCustomValue={false}
          inputBehavior="autohighlight"
          positioning={{ sameWidth: true, gutter: 2, flip: true, fitViewport: true }}
        >
          <Combobox.Label class="visually-hidden">{props.label}</Combobox.Label>
          <Combobox.Control class="combobox-control">
            {/* Multi: search input next to the chips. Single: a value + ▾ trigger. */}
            <Show
              when={isMulti()}
              fallback={
                <Combobox.Trigger
                  class="searchable-select-trigger"
                  aria-label={props.label}
                  aria-required={props.required === true ? 'true' : undefined}
                >
                  <span class="searchable-select-value">{singleDisplay()}</span>
                  <span class="combobox-trigger-glyph" aria-hidden="true">▾</span>
                </Combobox.Trigger>
              }
            >
              {searchInput()}
              <Combobox.Trigger class="combobox-trigger" tabindex="-1" aria-label={props.label}>▾</Combobox.Trigger>
            </Show>
          </Combobox.Control>
          {/* Body-portal so the popup floats above cards/dialogs and is never clipped. */}
          <Portal>
            <Combobox.Positioner class="combobox-positioner">
              <Combobox.Content class="combobox-content">
                {/* Single: a roomy, sticky search field at the TOP of the dropdown. */}
                <Show when={!isMulti()}>
                  <div class="combobox-search-row">
                    <span class="combobox-search" aria-hidden="true">{magnifier()}</span>
                    {searchInput()}
                  </div>
                </Show>
                <For each={filteredItems()}>
                  {(item) => (
                    <Combobox.Item item={item} class="combobox-item">
                      <Combobox.ItemText>{item.label}</Combobox.ItemText>
                      <Combobox.ItemIndicator class="combobox-indicator">✓</Combobox.ItemIndicator>
                    </Combobox.Item>
                  )}
                </For>
                <Combobox.Empty class="combobox-empty">{m.app_no_results()}</Combobox.Empty>
              </Combobox.Content>
            </Combobox.Positioner>
          </Portal>
        </Combobox.Root>
      </div>
    </div>
  )
}
