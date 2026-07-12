import { getJson, postJson } from './client'

// ── ADR-026 P1 project-import DTOs ───────────────────────────────────────────
// Snake_case, mirroring the P1b backend (GetProjectImportProposalsAction +
// ConfirmProjectImportAction) the /project-import/* endpoints serialize.

/** Which precedence rule produced a proposal's derived customer (ProjectImportProposal):
 *  tempo / tempo-default are confident; category is a weaker fallback; the rest
 *  carry no confident customer and need a human pick. */
export type DerivationSource =
  | 'tempo'
  | 'tempo-default'
  | 'category'
  | 'ambiguous'
  | 'none'
  | 'error'
  | 'not-a-project'

/** One parked Jira prefix with its derived Customer + Project proposal. Nothing
 *  is persisted until the admin confirms the row. */
export interface Proposal {
  jira_key: string
  project_id: number | null
  project_name: string | null
  jira_id_prefix: string
  derived_customer_name: string | null
  derived_customer_key: string | null
  derivation_source: DerivationSource
  /** Competing "Name [KEY]" labels — populated only for `ambiguous`. */
  candidate_customers: string[]
}

/** GET /project-import/proposals body. */
export interface ProposalsResponse {
  ticket_system_id: number
  proposals: Proposal[]
}

/** One confirmed row for POST /project-import/confirm. Exactly one of
 *  `customer_id` (pick existing) / `customer_name` (create-or-find-by-name). */
export interface ConfirmRow {
  jira_key: string
  project_name: string
  ticket_system_id: number
  customer_id?: number
  customer_name?: string
}

/** One persisted result row (ProjectImportConfirmationService::confirm). */
export interface ConfirmResultRow {
  jira_key: string
  project_id: number | null
  project_name: string | null
  customer_id: number | null
  customer_name: string | null
  ticket_system_id: number | null
  status: 'created' | 'existing'
}

/** POST /project-import/confirm body. */
export interface ConfirmResult {
  projects: ConfirmResultRow[]
}

/** Base cache key — the confirm action invalidates it so the confirmed prefixes
 *  (now resolvable) drop out on the next proposals fetch. */
export const projectImportKeys = {
  proposals: ['project-import', 'proposals'] as const,
}

/** GET /project-import/proposals?ticketSystem={id} — the parked prefixes and
 *  their derived proposals for one ticket system. */
export function proposalsQuery(ticketSystemId: number) {
  return {
    queryKey: [...projectImportKeys.proposals, ticketSystemId] as const,
    queryFn: () =>
      getJson<ProposalsResponse>('/project-import/proposals', { ticketSystem: ticketSystemId }),
    enabled: ticketSystemId > 0,
  }
}

/** POST /project-import/confirm — persist the confirmed rows; returns the
 *  created/linked projects with their per-row status. */
export function confirmProjectImport(rows: ConfirmRow[]): Promise<ConfirmResult> {
  return postJson<ConfirmResult>('/project-import/confirm', { rows })
}
