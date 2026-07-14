import { m } from '../../paraglide/messages.js'
import { ApiTokenControls } from '../ApiTokenControls'

/** Personal access tokens (ADR-021) — their own section: an automation
 *  concern with a wide scopes grid, not an account-security control. */
export function TokensSection() {
  return (
    <div class="stack-form">
      {/* One h2 per settings section so the page outline is h1 → h2;
          visually-hidden because the fieldset legend already shows the title. */}
      <h2 class="visually-hidden">{m.settings_apitoken_heading()}</h2>
      <fieldset class="settings-group">
        <legend>{m.settings_apitoken_heading()}</legend>
        <ApiTokenControls />
      </fieldset>
    </div>
  )
}
