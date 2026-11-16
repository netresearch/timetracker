/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { cleanup, fireEvent, render, screen } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import type { JiraAuthorizationNotice as Notice } from '../api/queries'
import { JiraAuthorizationNotice, mergeJiraNotices } from './JiraAuthorizationNotice'

const notice: Notice = { ticketSystemId: 3, ticketSystemName: 'Netresearch Jira', url: '/jira/connect/3' }

afterEach(() => cleanup())

describe('JiraAuthorizationNotice (#911)', () => {
  it('announces the refusal and links to the connect route', () => {
    render(() => <JiraAuthorizationNotice notices={[notice]} onDismiss={() => {}} />)

    const status = screen.getByRole('status')
    expect(status.textContent).toContain('did not reach Netresearch Jira')
    const link = screen.getByRole('link', { name: 'Authorize TimeTracker in Netresearch Jira' })
    expect(link.getAttribute('href')).toBe('/jira/connect/3')
  })

  it('renders nothing without notices', () => {
    render(() => <JiraAuthorizationNotice notices={[]} onDismiss={() => {}} />)

    expect(screen.queryByRole('status')).toBeNull()
  })

  it('calls onDismiss from its dismiss button', () => {
    const onDismiss = vi.fn()
    render(() => <JiraAuthorizationNotice notices={[notice]} onDismiss={onDismiss} />)

    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }))

    expect(onDismiss).toHaveBeenCalledOnce()
  })

  it('has no axe violations', async () => {
    const { container } = render(() => <JiraAuthorizationNotice notices={[notice]} onDismiss={() => {}} />)

    expect(await axe(container)).toHaveNoViolations()
  })
})

describe('mergeJiraNotices', () => {
  it('keeps the shown notices when a save brings none', () => {
    expect(mergeJiraNotices([notice], undefined)).toEqual([notice])
    expect(mergeJiraNotices([notice], [])).toEqual([notice])
  })

  it('adds a new system and keeps one notice per system', () => {
    const other: Notice = { ticketSystemId: 4, ticketSystemName: 'Customer Jira', url: '/jira/connect/4' }

    expect(mergeJiraNotices([notice], [notice, other])).toEqual([notice, other])
  })
})
