import { Combobox, createListCollection } from '@ark-ui/solid/combobox'
import { For, type JSX, Show } from 'solid-js'

import { m } from '../paraglide/messages.js'

/** An Ark combobox option: the stringified value carried verbatim + its label. */
export type ComboItem = { value: string; label: string }

/** Build the Ark list collection from already-filtered items (value ↔ label mapping). */
export const comboCollection = (items: ComboItem[]) =>
  createListCollection<ComboItem>({ items, itemToValue: (item) => item.value, itemToString: (item) => item.label })

/**
 * The leading magnifier glyph, wrapped in its `.combobox-search` decoration span.
 * Shared by the grid editor ({@link ChipSelect}) and the form control
 * ({@link SearchableSelect}) so both render the identical search affordance.
 */
export const ComboSearchIcon = (): JSX.Element => (
  <span class="combobox-search" aria-hidden="true">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
      <circle cx="10.5" cy="10.5" r="6.5" />
      <line x1="15.6" y1="15.6" x2="21" y2="21" />
    </svg>
  </span>
)

/**
 * The dropdown body shared by both comboboxes: an optional sticky search row at the
 * top (single-select only — multi keeps its search inline in the control), the
 * option list, and the empty state. Rendered inside the caller's `Combobox.Root` →
 * `Portal` → `Combobox.Positioner`, so Ark's context resolves normally.
 */
export function ComboboxContent(props: {
  items: ComboItem[]
  multiple: boolean
  searchInput: () => JSX.Element
}): JSX.Element {
  return (
    <Combobox.Content class="combobox-content">
      <Show when={!props.multiple}>
        <div class="combobox-search-row">
          <ComboSearchIcon />
          {props.searchInput()}
        </div>
      </Show>
      <For each={props.items}>
        {(item) => (
          <Combobox.Item item={item} class="combobox-item">
            <Combobox.ItemText>{item.label}</Combobox.ItemText>
            <Combobox.ItemIndicator class="combobox-indicator">✓</Combobox.ItemIndicator>
          </Combobox.Item>
        )}
      </For>
      <Combobox.Empty class="combobox-empty">{m.app_no_results()}</Combobox.Empty>
    </Combobox.Content>
  )
}
