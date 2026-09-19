/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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

/** The candidate this user booked on most recently; '' (never booked) loses to
 *  every date, and equal dates — in practice: no history on either — go to the
 *  HIGHEST id, the youngest project. Where one prefix is carried by several
 *  active projects they are usually a succession, and the survivor created last
 *  is the live one: for DHLSUP that is 982 "DHL (New) Shipping M2 Support",
 *  which is the project #687 names as the right answer, over 849. The id is
 *  compared rather than left to array order, because /getAllProjects runs
 *  `findAll()` with no ORDER BY and the row order is the database's to choose. */
function preferred<T extends DerivableProject>(candidates: T[]): T | undefined {
  return candidates.reduce<T | undefined>((best, candidate) => {
    if (best === undefined) {
      return candidate
    }
    if (candidate.lastBookedByUser !== best.lastBookedByUser) {
      return candidate.lastBookedByUser > best.lastBookedByUser ? candidate : best
    }

    return candidate.id > best.id ? candidate : best
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
 *
 * When the ONLY exact subticket hit sits on a retired project, the prefix rule
 * still runs and may fill in a different, active project: for the shape this was
 * written for — a support project superseded by its successor under the same
 * prefix — that successor is the better guess, and the person sees the filled
 * cell and can change it. The alternative, leaving the field empty, is the
 * safer-looking option but tells them nothing.
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
