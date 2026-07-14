import { WorklogImportSection } from '../WorklogImportSection'
import { WorklogSyncPreferences } from '../WorklogSyncPreferences'
import { PersonioOptIn } from './PersonioOptIn'
import { m } from '../../paraglide/messages.js'

/** Everything that moves data between the timetracker and external systems:
 *  Jira import (ADR-023 UC1), nightly-sync opt-ins (ADR-023 amendment),
 *  Personio attendance-export opt-in (ADR-024). */
export function SyncSection() {
  return (
    <>
      {/* One h2 for the whole Sync section so the sub-blocks' h3s (per ticket
          system) nest under an h2 rather than skipping h1 → h3; visually-hidden
          because each sub-block's fieldset legend already shows its title. */}
      <h2 class="visually-hidden">{m.settings_nav_sync()}</h2>
      <WorklogImportSection />
      <WorklogSyncPreferences />
      <PersonioOptIn />
    </>
  )
}
