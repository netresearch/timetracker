import { WorklogImportSection } from '../WorklogImportSection'
import { WorklogSyncPreferences } from '../WorklogSyncPreferences'
import { PersonioOptIn } from './PersonioOptIn'

/** Everything that moves data between the timetracker and external systems:
 *  Jira import (ADR-023 UC1), nightly-sync opt-ins (ADR-023 amendment),
 *  Personio attendance-export opt-in (ADR-024). */
export function SyncSection() {
  return (
    <>
      <WorklogImportSection />
      <WorklogSyncPreferences />
      <PersonioOptIn />
    </>
  )
}
