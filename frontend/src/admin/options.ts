/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { useQuery } from '@tanstack/solid-query'

import {
  activitiesQuery,
  customersQuery,
  type NamedOption,
  projectsQuery,
  teamsQuery,
  ticketSystemsQuery,
  usersQuery,
} from '../api/queries'
import type { OptionLookup, OptionSource } from './types'

/**
 * Loads the shared admin dropdown sources once and exposes a lookup (for form
 * selects and grid relation columns).
 */
export function useOptionSources() {
  const queries: Record<OptionSource, { data?: NamedOption[] }> = {
    customers: useQuery(customersQuery),
    projects: useQuery(projectsQuery),
    users: useQuery(usersQuery),
    teams: useQuery(teamsQuery),
    ticketSystems: useQuery(ticketSystemsQuery),
    activities: useQuery(activitiesQuery),
  }

  const lookup: OptionLookup = (source) => queries[source].data ?? []

  return { lookup }
}
