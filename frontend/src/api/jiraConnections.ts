/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { getJson } from './client'

/** A Jira ticket system the caller books worklogs on (GET /api/v2/jira/connections, #911). */
export interface JiraConnection {
  ticket_system_id: number
  ticket_system_name: string
  // A token is stored for the caller; Jira may still refuse it.
  connected: boolean
  // Same-origin route that starts the authorization and redirects to Jira.
  connect_url: string
}

export const jiraConnectionsQuery = () => ({
  queryKey: ['jira-connections'] as const,
  queryFn: () => getJson<{ connections: JiraConnection[] }>('/api/v2/jira/connections'),
})
