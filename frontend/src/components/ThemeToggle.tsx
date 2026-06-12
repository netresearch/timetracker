import { SegmentGroup } from '@ark-ui/solid/segment-group'
import { For } from 'solid-js'

import { m } from '../paraglide/messages.js'
import { setThemePreference, themePreference, type ThemePreference } from '../theme'

export function ThemeToggle() {
  const options: ReadonlyArray<{ value: ThemePreference; label: () => string }> = [
    { value: 'system', label: () => m.app_theme_system() },
    { value: 'light', label: () => m.app_theme_light() },
    { value: 'dark', label: () => m.app_theme_dark() },
  ]

  return (
    <SegmentGroup.Root
      class="theme-toggle"
      value={themePreference()}
      onValueChange={(details) => {
        if (details.value === 'system' || details.value === 'light' || details.value === 'dark') {
          setThemePreference(details.value)
        }
      }}
      aria-label={m.app_theme()}
    >
      <SegmentGroup.Indicator class="theme-toggle-indicator" />
      <For each={options}>
        {(option) => (
          <SegmentGroup.Item class="theme-toggle-item" value={option.value}>
            <SegmentGroup.ItemText>{option.label()}</SegmentGroup.ItemText>
            <SegmentGroup.ItemControl />
            <SegmentGroup.ItemHiddenInput />
          </SegmentGroup.Item>
        )}
      </For>
    </SegmentGroup.Root>
  )
}
