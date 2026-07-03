import { Show, type JSX } from 'solid-js'

import { appConfig } from '../config'
import { passkeysSupported } from '../lib/passkeys'
import { m } from '../paraglide/messages.js'
import { PasskeyControls, TwoFactorControls } from './SecuritySection'

/**
 * Full-screen enrolment gate, rendered in place of the app when the org mandates
 * 2FA and this user has no second factor yet (ADR-018). It reuses the Settings
 * enrolment controls; finishing either flow reloads the page, which re-renders
 * with `hasTwoFactor` true and lets the app through. A logout link is the only
 * other way out — a user who cannot enrol right now can still leave.
 */
export function TwoFactorGate(): JSX.Element {
  const reload = (): void => globalThis.location.reload()

  return (
    <main class="twofactor-gate">
      <div class="twofactor-gate-card">
        <h1 class="twofactor-gate-title">{m.twofactor_gate_title()}</h1>
        <p class="twofactor-gate-intro">{m.twofactor_gate_intro()}</p>

        <TwoFactorControls initiallyEnabled={false} onEnrolled={reload} />
        <Show when={passkeysSupported()}>
          <PasskeyControls onRegistered={reload} />
        </Show>

        <a class="twofactor-gate-logout" href={appConfig().logoutUrl}>{m.twofactor_gate_logout()}</a>
      </div>
    </main>
  )
}
