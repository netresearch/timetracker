/** The project fields the ticket→project derivation reads. Kept structural so a
 *  test fixture (and any future caller) needs no full TrackingProject. */
export interface DerivableProject {
  id: number
  active: boolean
  jiraId: string
  subtickets: string
  /** ISO day of this user's most recent booking on the project, '' when never. */
  lastBookedByUser: string
}

const keys = (list: string): string[] => list.toUpperCase().split(/[\s,]+/).filter((key) => key !== '')

/** Most recently booked by this user first; never booked sorts last, and an
 *  untouched pair keeps the lowest id — the order the list arrives in. */
function preferred<T extends DerivableProject>(candidates: T[]): T | undefined {
  return candidates.reduce<T | undefined>((best, candidate) => {
    if (best === undefined) {
      return candidate
    }

    return candidate.lastBookedByUser > best.lastBookedByUser ? candidate : best
  }, undefined)
}

/**
 * The project a ticket key maps to on the entry form, or undefined when nothing
 * matches and the person has to pick one.
 *
 * Only ACTIVE projects are offered (#687): SaveEntryAction rejects a newly
 * assigned inactive project with 400, so auto-selecting one produced a row that
 * could not be saved — and, being the lowest id, it was usually a retired
 * project that merely shares the prefix.
 *
 * An exact `subtickets` hit beats the `jiraId` prefix rule: the synced list
 * enumerates specific keys (possibly from another Jira project), so it is more
 * precise, and the backend accepts it the same way (SaveEntryAction::isKnownSubticket).
 * Within either group, ties go to the project this user booked on last.
 */
export function deriveProjectForTicket<T extends DerivableProject>(ticket: string, projects: T[]): T | undefined {
  const ticketKey = ticket.toUpperCase().trim()
  if (ticketKey === '') {
    return undefined
  }

  const bookable = projects.filter((project) => project.active)
  const bySubticket = preferred(bookable.filter((project) => keys(project.subtickets).includes(ticketKey)))
  if (bySubticket !== undefined) {
    return bySubticket
  }

  const prefix = ticketKey.split(/[-:]/)[0] ?? ''
  if (prefix === '') {
    return undefined
  }

  // jiraId is a comma/space-separated list of allowed prefixes (the backend splits
  // it the same way in validateTicketPrefix), so match membership — an exact ===
  // missed multi-prefix projects (#453).
  return preferred(bookable.filter((project) => keys(project.jiraId).includes(prefix)))
}
