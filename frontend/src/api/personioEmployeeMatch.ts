import { getJson, postJson } from './client'

// ── ADR-024 P3 employee-match DTOs ───────────────────────────────────────────
// Snake_case, mirroring the backend (GetPersonioEmployeeMatchesAction +
// ConfirmPersonioEmployeeMatchesAction) the /personio/employee-matches endpoints
// serialize.

/** How a proposal matched: `email` (e-mail localpart, confident) or `name`
 *  (firstname.lastname, a weaker fallback). */
export type MatchSource = 'email' | 'name'

/** One proposed TT user → Personio employee-id match. Nothing is persisted until
 *  the admin confirms the row. */
export interface EmployeeMatchProposal {
  user_id: number
  username: string
  person_id: string
  person_name: string
  source: MatchSource
}

/** GET /personio/employee-matches body. */
export interface EmployeeMatchesResponse {
  proposals: EmployeeMatchProposal[]
}

/** One applied mapping returned by the confirm endpoint. */
export interface AppliedMatch {
  user_id: number
  username: string
  person_id: string
}

/** POST /personio/employee-matches/confirm body. */
export interface ConfirmMatchesResult {
  applied: AppliedMatch[]
}

/** Base cache key — the confirm action invalidates it so a mapped user drops out
 *  of the next proposals fetch (the endpoint only lists users with no id yet). */
export const personioEmployeeMatchKeys = {
  proposals: ['personio-employee-match', 'proposals'] as const,
}

/** GET /personio/employee-matches — the unmapped users with their proposed
 *  Personio employee-id matches. */
export function employeeMatchesQuery() {
  return {
    queryKey: [...personioEmployeeMatchKeys.proposals] as const,
    queryFn: () => getJson<EmployeeMatchesResponse>('/personio/employee-matches'),
  }
}

/** POST /personio/employee-matches/confirm — write the confirmed user→id
 *  mappings; returns the applied rows. */
export function confirmEmployeeMatches(
  matches: { user_id: number; person_id: string }[],
): Promise<ConfirmMatchesResult> {
  return postJson<ConfirmMatchesResult>('/personio/employee-matches/confirm', { matches })
}
