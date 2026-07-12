import { Show } from 'solid-js'

import { m } from '../paraglide/messages.js'

/**
 * ADR-025 §7 marker for an entry row: a small, non-colour badge shown when a
 * machine performed the labour (`source === 'agent'`) and, separately, when the
 * figure is an agent-derived estimate (`estimated === true`). Each badge carries
 * text and an icon — colour is never the only cue (WCAG 1.4.1). The agent badge
 * says the hour is machine time (never summed into human labour); the estimated
 * badge flags a human-source figure a person should still eyeball before it
 * hardens into an attendance record (§5).
 */
export function EntrySourceBadge(props: { source?: string | null; estimated?: boolean }) {
  return (
    <>
      <Show when={props.source === 'agent'}>
        <span class="entry-badge is-agent" title={m.entry_source_agent_hint()}>
          <span class="entry-badge-icon" aria-hidden="true">⚙</span>
          {m.entry_source_agent()}
        </span>
      </Show>
      <Show when={props.estimated === true}>
        <span class="entry-badge is-estimated" title={m.entry_estimated_hint()}>
          <span class="entry-badge-icon" aria-hidden="true">≈</span>
          {m.entry_estimated()}
        </span>
      </Show>
    </>
  )
}
