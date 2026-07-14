import { m } from '../../paraglide/messages.js'
import { ApiTokenControls } from '../ApiTokenControls'

/** Personal access tokens (ADR-021) — their own section: an automation
 *  concern with a wide scopes grid, not an account-security control. */
export function TokensSection() {
  return (
    <div class="stack-form">
      {/* One h2 per settings section so the page outline is h1 → h2.
          The h2 lives INSIDE the legend: a single text node serves both the
          outline and the group name, so screen readers don't announce the
          title twice (hidden heading + identically-named legend). */}
      <fieldset class="settings-group">
        <legend><h2>{m.settings_apitoken_heading()}</h2></legend>
        <ApiTokenControls />
      </fieldset>
    </div>
  )
}
