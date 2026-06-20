import { Combobox, createListCollection } from '@ark-ui/solid/combobox'
import { createMemo, createSignal, For, onMount, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import type { FieldDef, FormValues, OptionLookup } from '../admin/types'
import { getEnterBehavior } from './gridEditPref'
import { fieldSelectOptions } from './inlineGridEdit'
import { m } from '../paraglide/messages.js'

type Item = { value: string; label: string }

/**
 * Filterable chip combobox for an inline relation cell — one component for single
 * (max one chip) and multi select, replacing the native-<select> editor and the
 * hand-rolled InlineMultiSelect across both grids. Built on Ark UI Combobox:
 * type to filter, chips for the selection, keyboard-navigable listbox.
 *
 * Boundary: Ark deals in string[]; our option ids are numbers — coerced at the
 * edges. Selected chips resolve through the FULL label map, so a value filtered
 * out of the live list keeps its chip label. Every commit funnels through the
 * controller's onCommit so the grid's cascade / auto-save still fire.
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
  const initialIds = (): number[] => {
    if (Array.isArray(props.initial)) {
      return (props.initial as unknown[]).map((value) => Number(value)).filter((value) => value > 0)
    }
    const single = Number(props.initial ?? 0)

    return single > 0 ? [single] : []
  }

  const [selected, setSelected] = createSignal<number[]>(initialIds())
  const [inputValue, setInputValue] = createSignal('')
  const [open, setOpen] = createSignal(false)
  let rootEl: HTMLDivElement | undefined
  let inputEl: HTMLInputElement | undefined
  let done = false

  const allItems = createMemo<Item[]>(() => fieldSelectOptions(props.field, props.options).map((option) => ({ value: String(option.value), label: option.label })))
  const labelOf = (id: number): string => allItems().find((item) => item.value === String(id))?.label ?? String(id)
  const filteredItems = createMemo<Item[]>(() => {
    const query = inputValue().trim().toLowerCase()

    return query === '' ? allItems() : allItems().filter((item) => item.label.toLowerCase().includes(query))
  })
  // Ark requires a collection; rebuild it from the filtered items (we filter, not zag).
  const collection = createMemo(() => createListCollection<Item>({ items: filteredItems(), itemToValue: (item) => item.value, itemToString: (item) => item.label }))

  const coerced = (): FormValues[string] => {
    if (props.multiple) {
      return selected()
    }
    const first = selected()[0]
    if (first === undefined) {
      return props.field.stringValue === true ? '' : 0
    }

    return props.field.stringValue === true ? String(first) : first
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
  })

  return (
    <div
      ref={(element) => { rootEl = element }}
      class="inline-editor inline-tags chip-select"
      onFocusOut={() => {
        // Commit only once focus has actually left the whole widget — including
        // the body-portalled popup (data-chipselect-popup) — checked after focus
        // settles, so moving into the listbox doesn't commit/close the editor.
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
      <span class="tag-list" role="list">
        <For each={selected()}>
          {(id) => (
            <span class="tag" role="listitem">
              <span class="tag-label">{labelOf(id)}</span>
              <Show when={props.multiple}>
                <button
                  type="button"
                  class="tag-remove"
                  tabindex="-1"
                  aria-label={`${m.admin_delete()}: ${labelOf(id)}`}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => { setSelected(selected().filter((value) => value !== id)); inputEl?.focus() }}
                >×</button>
              </Show>
            </span>
          )}
        </For>
      </span>

      <Combobox.Root
        collection={collection()}
        multiple={props.multiple}
        value={selected().map(String)}
        onValueChange={(details) => {
          if (props.multiple) {
            setSelected(details.value.map(Number))
          } else {
            const picked = details.value.at(-1)
            setSelected(picked !== undefined ? [Number(picked)] : [])
            finish(getEnterBehavior()) // single pick commits + moves per the user's Enter pref
          }
        }}
        inputValue={inputValue()}
        onInputValueChange={(details) => setInputValue(details.inputValue)}
        defaultOpen
        onOpenChange={(details) => setOpen(details.open)}
        openOnClick
        closeOnSelect={!props.multiple}
        allowCustomValue={false}
        inputBehavior="autohighlight"
        positioning={{ sameWidth: true, gutter: 2, flip: true }}
        onInteractOutside={(event) => {
          // Keep the popup open while focus/clicks stay within the editing cell.
          const target = event.target as Node | null
          if (target !== null && rootEl?.contains(target)) {
            event.preventDefault()
          }
        }}
      >
        <Combobox.Control class="combobox-control">
          <Combobox.Input
            ref={(element) => { inputEl = element }}
            class="combobox-input"
            aria-label={props.label}
            placeholder={!props.multiple && selected().length > 0 ? labelOf(selected()[0]!) : ''}
            onKeyDown={(event) => {
              // The listbox owns Arrow/Enter/Escape while open; the cell owns
              // Tab (always) and Enter/Escape only when the list is closed.
              if (event.key === 'Tab') {
                event.preventDefault()
                event.stopPropagation()
                finish(event.shiftKey ? 'left' : 'right')
              } else if (event.key === 'Enter' && !open()) {
                event.preventDefault()
                event.stopPropagation()
                finish(getEnterBehavior())
              } else if (event.key === 'Escape' && !open()) {
                event.preventDefault()
                event.stopPropagation()
                cancel()
              }
            }}
          />
          <Combobox.Trigger class="combobox-trigger" tabindex="-1" aria-label={props.label}>▾</Combobox.Trigger>
        </Combobox.Control>
        {/* Body-portal so the popup floats above the grid and is never clipped by
            the .table-scroll overflow; data-chipselect-popup whitelists it in the
            grid's focus-out logic so opening it doesn't save/close the row. */}
        <Portal>
          <Combobox.Positioner class="combobox-positioner" data-chipselect-popup>
            <Combobox.Content class="combobox-content">
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
