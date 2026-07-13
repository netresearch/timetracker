import { A, useParams } from '@solidjs/router'
import { createMemo, For, type Component } from 'solid-js'
import { Dynamic } from 'solid-js/web'

import { SecuritySection } from '../components/SecuritySection'
import { AccountSection } from '../components/settings/AccountSection'
import { AppearanceSection } from '../components/settings/AppearanceSection'
import { SyncSection } from '../components/settings/SyncSection'
import { TokensSection } from '../components/settings/TokensSection'
import { m } from '../paraglide/messages.js'

interface Section {
  key: string
  label: () => string
  component: Component
}

// Order = display order. Only the active section mounts (lazy per section:
// e.g. the passkey/token lists fetch only when their section is opened).
const SECTIONS: Section[] = [
  { key: 'account', label: () => m.settings_nav_account(), component: AccountSection },
  { key: 'appearance', label: () => m.settings_nav_appearance(), component: AppearanceSection },
  { key: 'security', label: () => m.settings_nav_security(), component: SecuritySection },
  { key: 'tokens', label: () => m.settings_nav_tokens(), component: TokensSection },
  { key: 'sync', label: () => m.settings_nav_sync(), component: SyncSection },
]

export default function Settings() {
  const params = useParams()
  // Unknown/absent :section falls back to the first section (tolerant, like Admin).
  const active = createMemo(() => SECTIONS.find((s) => s.key === params.section) ?? SECTIONS[0]!)

  return (
    <section class="form-page settings-page settings-layout">
      <nav class="settings-nav" aria-label={m.settings_title()}>
        <For each={SECTIONS}>
          {(s) => (
            <A
              href={`/settings/${s.key}`}
              class="settings-nav-link"
              aria-current={active().key === s.key ? 'page' : undefined}
            >
              {s.label()}
            </A>
          )}
        </For>
      </nav>
      <div class="settings-content">
        <Dynamic component={active().component} />
      </div>
    </section>
  )
}
