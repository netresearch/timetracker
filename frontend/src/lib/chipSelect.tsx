import { Combobox, createListCollection } from '@ark-ui/solid/combobox'
import { createMemo, createSignal, For, type JSX, onMount, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import type { FieldDef, FormValues, OptionLookup } from '../admin/types'
import { getEnterBehavior } from './gridEditPref'
import { fieldSelectOptions } from './inlineGridEdit'
import { m } from '../paraglide/messages.js'

type Item = { value: string; label: string }

// The "clear to none" pseudo-option for an optional single relation select. A
// sentinel (never a real option value — relation values stringify to digits) so
// it can't collide with a genuine option and never enters the committed value.
const CLEAR_VALUE = '__clear__'

/**
 * Filterable chip combobox for an inline relation cell, built on Ark UI Combobox.
 *
 * Two layouts that share all the logic:
 * - MULTI: removable chips + a leading magnifier + the search input, all IN the
 *   cell (admin cells are wide enough).
 * - SINGLE: the cell stays compact — just the current value + a ▾ — and the search
 *   field lives at the top of the (roomy, body-portalled) dropdown, so it is never
 *   constrained by a narrow column. The current value is the checked list item.
 *
 * Boundary: Ark deals in string[] and our option values are *either* numeric ids
 * (relations) or strings (e.g. user `type`/`locale`); the selection is carried
 * verbatim as the option's string value and converted to the field's payload type
 * only in coerced() — so a string select round-trips as its string, never NaN.
 */
export function ChipSelect(props: {
  field: FieldDef
  label: string
  initial: FormValues[string]
  options: OptionLookup
  multiple: boolean
  onCommit: (value: FormValues[string], direction?: 'down' | 'left' | 'right' | 'stay') => void
  onCancel: () => void
}) {
  const initialValues = (): string[] => {
    const raw = props.initial
    if (Array.isArray(raw)) {
      return (raw as unknown[]).map((value) => Number(value)).filter((value) => value > 0).map(String)
    }
    if (props.field.stringValue === true) {
      const value = raw === undefined || raw === null ? '' : String(raw)

      return value === '' ? [] : [value]
    }
    const single = Number(raw ?? 0)

    return single > 0 ? [String(single)] : []
  }

  const [selected, setSelected] = createSignal<string[]>(initialValues())
  const [inputValue, setInputValue] = createSignal('')
  // Seed to match `defaultOpen`: Ark enters the open state as the machine's initial
  // state without firing onOpenChange, so a signal seeded false would mis-read the
  // open list as closed and route the first Enter to commit-the-cell.
  const [open, setOpen] = createSignal(true)
  let rootEl: HTMLDivElement | undefined
  let inputEl: HTMLInputElement | undefined
  let done = false

  const allItems = createMemo<Item[]>(() => fieldSelectOptions(props.field, props.options).map((option) => ({ value: String(option.value), label: option.label })))
  const labelOf = (value: string): string => allItems().find((item) => item.value === value)?.label ?? value
  // Offer an explicit "none" only for an optional, numeric (relation) single select.
  const showClear = (): boolean => !props.multiple && props.field.required !== true && props.field.stringValue !== true
  const filteredItems = createMemo<Item[]>(() => {
    const query = inputValue().trim().toLowerCase()
    const base = query === '' ? allItems() : allItems().filter((item) => item.label.toLowerCase().includes(query))

    return showClear() && query === '' ? [{ value: CLEAR_VALUE, label: m.app_select_none() }, ...base] : base
  })
  const collection = createMemo(() => createListCollection<Item>({ items: filteredItems(), itemToValue: (item) => item.value, itemToString: (item) => item.label }))

  const coerced = (): FormValues[string] => {
    if (props.multiple) {
      return selected().map(Number)
    }
    const first = selected()[0]
    if (first === undefined) {
      return props.field.stringValue === true ? '' : 0
    }

    return props.field.stringValue === true ? first : Number(first)
  }

  const finish = (direction?: 'down' | 'left' | 'right' | 'stay'): void => {
    if (done) {
      return
    }
    done = true
    props.onCommit(coerced(), direction)
  }
  const cancel = (): void => {
    if (done) {
      return
    }
    done = true
    props.onCancel()
  }

  onMount(() => {
    inputEl?.focus()
    // The single-select input lives in the body-portalled popup; if it isn't ready
    // on the first tick, focus it on the next frame so typing starts immediately.
    if (document.activeElement !== inputEl) {
      requestAnimationFrame(() => inputEl?.focus())
    }
  })

  const onInputKeyDown = (event: KeyboardEvent): void => {
    // The listbox owns Arrow/Enter while open; the cell owns Tab (always), Enter when
    // the list is closed, and Backspace to drop the last chip when the filter is empty.
    // Escape with the list open is handled in onOpenChange (zag consumes it first);
    // this branch covers the closed list and environments where zag doesn't.
    if (event.key === 'Tab') {
      event.preventDefault()
      event.stopPropagation()
      finish(event.shiftKey ? 'left' : 'right')
    } else if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      cancel()
    } else if (event.key === 'Enter' && !open()) {
      event.preventDefault()
      event.stopPropagation()
      finish(getEnterBehavior())
    } else if (event.key === 'Backspace' && props.multiple && inputValue() === '' && selected().length > 0) {
      event.preventDefault()
      setSelected(selected().slice(0, -1))
    }
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
      placeholder={props.multiple && selected().length === 0 ? m.app_type_to_add() : m.app_type_to_search()}
      onKeyDown={onInputKeyDown}
    />
  )

  return (
    <div
      ref={(element) => { rootEl = element }}
      class={`inline-editor chip-select${props.multiple ? ' inline-tags' : ''}`}
      onFocusOut={() => {
        // Commit once focus leaves the whole widget — including the body-portalled
        // popup (data-chipselect-popup) — checked after focus settles so moving into
        // the listbox/search doesn't commit/close the editor.
        // eslint-disable-next-line solid/reactivity -- deferred commit after focus settles
        queueMicrotask(() => {
          const active = document.activeElement
          const inPopup = active instanceof HTMLElement && active.closest('[data-chipselect-popup]') !== null
          if (!done && rootEl !== undefined && !rootEl.contains(active) && !inPopup) {
            finish()
          }
        })
      }}
    >
      {/* Multi: a leading magnifier in the cell. Single's search lives in the popup. */}
      <Show when={props.multiple}>
        <span class="combobox-search" aria-hidden="true">{magnifier()}</span>
      </Show>

      {/* Selection chips in the cell: multi = removable; single = the current value
          (no ×) so the cell stays a compact value display. */}
      <span class="tag-list" role="list">
        <For each={selected()}>
          {(value) => (
            <span class="tag" role="listitem">
              <span class="tag-label">{labelOf(value)}</span>
              <Show when={props.multiple}>
                <button
                  type="button"
                  class="tag-remove"
                  tabindex="-1"
                  aria-label={`${m.admin_delete()}: ${labelOf(value)}`}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => { setSelected(selected().filter((current) => current !== value)); inputEl?.focus() }}
                >×</button>
              </Show>
            </span>
          )}
        </For>
      </span>

      {/* Announce selection changes — Ark's own live region only narrates the
          highlighted option during navigation. */}
      <span class="visually-hidden" role="status" aria-live="polite">
        {selected().map(labelOf).join(', ')}
      </span>

      <Combobox.Root
        class="chip-select-root"
        collection={collection()}
        multiple={props.multiple}
        value={selected()}
        onValueChange={(details) => {
          if (props.multiple) {
            setSelected(details.value)
          }
        }}
        onSelect={(details) => {
          if (props.multiple) {
            return
          }
          setSelected(details.itemValue === CLEAR_VALUE ? [] : [details.itemValue])
          finish(getEnterBehavior()) // single pick commits + moves per the user's Enter pref
        }}
        inputValue={inputValue()}
        onInputValueChange={(details) => setInputValue(details.inputValue)}
        defaultOpen
        onOpenChange={(details) => {
          setOpen(details.open)
          // A single Escape cancels the whole cell edit: zag consumes Escape on a
          // document listener to close ITS list before any element-level handler runs,
          // so we hook the close itself (reason 'escape-key'). Other close reasons
          // (item-select, interact-outside) must not cancel.
          if (!details.open && details.reason === 'escape-key') {
            cancel()
          }
        }}
        openOnClick
        closeOnSelect={!props.multiple}
        allowCustomValue={false}
        inputBehavior="autohighlight"
        // Multi tethers the popup to the (wide) cell; single lets the popup size to
        // its own content so the search field is never bound to a narrow column.
        positioning={{ sameWidth: props.multiple, gutter: 2, flip: true, fitViewport: true }}
        onInteractOutside={(event) => {
          const target = event.target as Node | null
          if (target !== null && rootEl?.contains(target)) {
            event.preventDefault() // a click within the cell keeps editing
          } else {
            finish() // a genuine outside interaction commits (covers single's popup input)
          }
        }}
      >
        <Combobox.Label class="visually-hidden">{props.label}</Combobox.Label>
        <Combobox.Control class="combobox-control">
          {/* Multi: the search input sits in the cell, next to the chips. */}
          <Show when={props.multiple}>{searchInput()}</Show>
          <Combobox.Trigger class="combobox-trigger" tabindex="-1" aria-label={props.label}>▾</Combobox.Trigger>
        </Combobox.Control>
        {/* Body-portal so the popup floats above the grid and is never clipped by the
            .table-scroll overflow; data-chipselect-popup whitelists it in the grid's
            focus-out logic so opening it doesn't save/close the row. */}
        <Portal>
          <Combobox.Positioner class="combobox-positioner" data-chipselect-popup>
            <Combobox.Content class="combobox-content">
              {/* Single: a roomy, sticky search field at the TOP of the dropdown —
                  not constrained by the narrow cell. */}
              <Show when={!props.multiple}>
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
  )
}
