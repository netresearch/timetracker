/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { cleanup, screen } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../../test/renderWithProviders'
import type { JiraConnection } from '../../api/jiraConnections'

const connectionsQueryFn = vi.fn()

vi.mock('../../api/jiraConnections', () => ({
  jiraConnectionsQuery: () => ({
    queryKey: ['jira-connections'],
    queryFn: () => connectionsQueryFn(),
  }),
}))

const { JiraConnections } = await import('./JiraConnections')

function connection(overrides: Partial<JiraConnection> = {}): JiraConnection {
  return {
    ticket_system_id: 3,
    ticket_system_name: 'Netresearch Jira',
    connected: false,
    connect_url: '/jira/connect/3',
    ...overrides,
  }
}

afterEach(() => {
  cleanup()
  connectionsQueryFn.mockReset()
})

describe('JiraConnections (#911)', () => {
  it('links a never-connected system to its connect route', async () => {
    connectionsQueryFn.mockResolvedValue({ connections: [connection()] })
    renderWithProviders(() => <JiraConnections />)

    const link = await screen.findByRole('link', { name: 'Connect Jira' })
    expect(link.getAttribute('href')).toBe('/jira/connect/3')
    expect(screen.getByText('Not connected.')).toBeTruthy()
  })

  it('still offers to reconnect a system with a stored token', async () => {
    connectionsQueryFn.mockResolvedValue({ connections: [connection({ connected: true })] })
    renderWithProviders(() => <JiraConnections />)

    const link = await screen.findByRole('link', { name: 'Reconnect Jira' })
    expect(link.getAttribute('href')).toBe('/jira/connect/3')
  })

  it('says so when no Jira system books worklogs', async () => {
    connectionsQueryFn.mockResolvedValue({ connections: [] })
    renderWithProviders(() => <JiraConnections />)

    expect(await screen.findByText('No Jira ticket system books worklogs.')).toBeTruthy()
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('reports a failed load', async () => {
    connectionsQueryFn.mockRejectedValue(new Error('boom'))
    renderWithProviders(() => <JiraConnections />)

    expect(await screen.findByRole('alert')).toBeTruthy()
  })

  it('has no axe violations', async () => {
    connectionsQueryFn.mockResolvedValue({ connections: [connection(), connection({ ticket_system_id: 4, ticket_system_name: 'Customer Jira', connected: true, connect_url: '/jira/connect/4' })] })
    const { container } = renderWithProviders(() => <JiraConnections />)
    await screen.findByRole('link', { name: 'Reconnect Jira' })

    expect(await axe(container)).toHaveNoViolations()
  })
})
