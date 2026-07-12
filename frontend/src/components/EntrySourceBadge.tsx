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
          <svg class="entry-badge-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="3.2" />
            <path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5 5l2.1 2.1M16.9 16.9 19 19M19 5l-2.1 2.1M7.1 16.9 5 19" />
          </svg>
          {m.entry_source_agent()}
        </span>
      </Show>
      <Show when={props.estimated === true}>
        <span class="entry-badge is-estimated" title={m.entry_estimated_hint()}>
          <svg class="entry-badge-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M4 9c2-2.6 4-2.6 6 0s4 2.6 6 0" />
            <path d="M4 15c2-2.6 4-2.6 6 0s4 2.6 6 0" />
          </svg>
          {m.entry_estimated()}
        </span>
      </Show>
    </>
  )
}
