import { describe, expect, it } from 'vitest'
import { deriveProjectForTicket, type DerivableProject } from './projectDerivation'

const project = (id: number, active: boolean, jiraId: string, extra: Partial<DerivableProject> = {}): DerivableProject => ({
  id,
  active,
  jiraId,
  subtickets: '',
  lastBookedByUser: '',
  ...extra,
})

// The rows behind #687, copied from production: seven projects share the DHLSUP
// prefix and only 849/982 are still active. Ordered by id, as /getAllProjects
// returns them.
const dhl = (): DerivableProject[] => [
  project(49, false, 'DHLSUP'), // DHL IntraShip Support — the one the report shows
  project(457, false, 'DHLSUP'),
  project(848, false, 'DHLSUP'),
  project(849, true, 'DHLSUP'), // DHL Shipping M1 Support
  project(850, false, 'DHLSUP'),
  project(870, false, 'DHLSUP'),
  project(982, true, 'DHLSUP'), // DHL (New) Shipping M2 Support
]

describe('deriveProjectForTicket', () => {
  it('never derives an inactive project (#687)', () => {
    const derived = deriveProjectForTicket('DHLSUP-123', dhl())

    expect(derived?.id).not.toBe(49)
    expect(derived?.active).toBe(true)
  })

  it('prefers the project this user booked on last when several are active', () => {
    const projects = dhl().map((candidate) => (candidate.id === 982 ? { ...candidate, lastBookedByUser: '2026-09-09' } : candidate))

    expect(deriveProjectForTicket('DHLSUP-123', projects)?.id).toBe(982)
  })

  it('falls back to the first active match when the user has no history', () => {
    expect(deriveProjectForTicket('DHLSUP-123', dhl())?.id).toBe(849)
  })

  it('ignores a booking history on an inactive project', () => {
    const projects = dhl().map((candidate) => (candidate.id === 49 ? { ...candidate, lastBookedByUser: '2026-09-16' } : candidate))

    expect(deriveProjectForTicket('DHLSUP-123', projects)?.id).toBe(849)
  })

  it('lets an exact subticket hit win over a prefix match', () => {
    const projects = [...dhl(), project(1000, true, 'OTHER', { subtickets: 'DHLSUP-123, DHLSUP-124' })]

    expect(deriveProjectForTicket('DHLSUP-123', projects)?.id).toBe(1000)
  })

  it('does not let an INACTIVE subticket hit shadow an active prefix match', () => {
    const projects = [...dhl(), project(1000, false, 'OTHER', { subtickets: 'DHLSUP-123' })]

    expect(deriveProjectForTicket('DHLSUP-123', projects)?.id).toBe(849)
  })

  it('matches a prefix listed among several (#453)', () => {
    const projects = [project(896, true, 'OPSDHL, DHLX')]

    expect(deriveProjectForTicket('DHLX-7', projects)?.id).toBe(896)
  })

  it('accepts a colon separator and normalises case', () => {
    expect(deriveProjectForTicket('dhlsup:9', dhl())?.id).toBe(849)
  })

  it('returns undefined for an empty ticket or no match', () => {
    expect(deriveProjectForTicket('   ', dhl())).toBeUndefined()
    expect(deriveProjectForTicket('NOPE-1', dhl())).toBeUndefined()
  })
})
