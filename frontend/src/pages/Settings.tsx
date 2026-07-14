import { A, useLocation, useParams } from '@solidjs/router'
import { createEffect, createMemo, For, type Component } from 'solid-js'
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

// Remembers the section the Settings URL last selected. When a page-level modal
// (e.g. /ui/help) opens over Settings, App.tsx re-renders Settings as the modal's
// background — but the live route is the modal's, so useParams() no longer carries
// the :section segment. The background falls back to this so the (dimmed) backdrop
// keeps its section instead of flipping to Account (mirrors Admin's lastAdminEntity).
let lastSettingsSection: string | undefined

export default function Settings() {
  const params = useParams()
  const location = useLocation()
  // True when Settings is the live route; false when it's a modal's background page.
  const onSettingsRoute = () => location.pathname.replace(/^\/ui/, '').startsWith('/settings')
  // While Settings is the live route the URL segment is authoritative (and is
  // recorded); as a background it falls back to the last recorded segment.
  createEffect(() => {
    if (onSettingsRoute()) {
      lastSettingsSection = params.section
    }
  })
  // Unknown/absent :section falls back to the first section (tolerant, like Admin);
  // as a background (no live :section) it falls back to the last active section.
  const active = createMemo(() => {
    const key = params.section ?? (onSettingsRoute() ? undefined : lastSettingsSection)

    return SECTIONS.find((s) => s.key === key) ?? SECTIONS[0]!
  })

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
