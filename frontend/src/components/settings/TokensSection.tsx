import { m } from '../../paraglide/messages.js'
import { ApiTokenControls } from '../ApiTokenControls'

/** Personal access tokens (ADR-021) — their own section: an automation
 *  concern with a wide scopes grid, not an account-security control. */
export function TokensSection() {
  return (
    <div class="stack-form">
      <fieldset class="settings-group">
        <legend>{m.settings_apitoken_heading()}</legend>
        <ApiTokenControls />
      </fieldset>
    </div>
  )
}
