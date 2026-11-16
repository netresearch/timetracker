/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { cleanup, fireEvent, render, screen } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import type { JiraAuthorizationNotice as Notice } from '../api/queries'
import { JiraAuthorizationNotice, jiraNoticeAnnouncement, mergeJiraNotices } from './JiraAuthorizationNotice'

const notice: Notice = { ticketSystemId: 3, ticketSystemName: 'Netresearch Jira', url: '/jira/connect/3' }

afterEach(() => cleanup())

describe('JiraAuthorizationNotice (#911)', () => {
  it('shows the refusal and links to the connect route', () => {
    const { container } = render(() => <JiraAuthorizationNotice notices={[notice]} onDismiss={() => {}} />)

    expect(container.textContent).toContain('did not reach Netresearch Jira')
    const link = screen.getByRole('link', { name: 'Authorize TimeTracker in Netresearch Jira' })
    expect(link.getAttribute('href')).toBe('/jira/connect/3')
  })

  it('is not a live region of its own (the page announces it)', () => {
    render(() => <JiraAuthorizationNotice notices={[notice]} onDismiss={() => {}} />)

    expect(screen.queryByRole('status')).toBeNull()
    expect(document.querySelector('[aria-live]')).toBeNull()
  })

  it('renders nothing without notices', () => {
    const { container } = render(() => <JiraAuthorizationNotice notices={[]} onDismiss={() => {}} />)

    expect(container.textContent).toBe('')
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

describe('jiraNoticeAnnouncement', () => {
  it('is empty without notices and names each refusing system otherwise', () => {
    const other: Notice = { ticketSystemId: 4, ticketSystemName: 'Customer Jira', url: '/jira/connect/4' }

    expect(jiraNoticeAnnouncement([])).toBe('')
    const text = jiraNoticeAnnouncement([notice, other])
    expect(text).toContain('did not reach Netresearch Jira')
    expect(text).toContain('did not reach Customer Jira')
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
