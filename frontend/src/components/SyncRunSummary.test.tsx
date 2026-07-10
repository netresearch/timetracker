import { render } from '@solidjs/testing-library'
import { describe, expect, it } from 'vitest'
import { axe } from 'vitest-axe'

import type { SyncRun } from '../api/worklogSync'
import { SyncRunSummary } from './SyncRunSummary'

function makeRun(overrides: Partial<SyncRun> = {}): SyncRun {
  return {
    id: 1,
    type: 'import',
    status: 'completed',
    ticket_system_id: 1,
    triggered_by: 'jdoe',
    scope: {},
    counters: {},
    started_at: '2026-07-10T08:00:00+00:00',
    finished_at: '2026-07-10T08:01:00+00:00',
    ...overrides,
  }
}

describe('SyncRunSummary', () => {
  it('renders the status badge, counters and one item for a completed run', async () => {
    const run = makeRun({
      status: 'completed',
      counters: { created: 3, conflicts: 1 },
      items: [
        {
          kind: 'conflict',
          issue_key: 'ABC-1',
          remote_worklog_id: null,
          entry_id: 7,
          author: 'jdoe',
          reason: 'both changed',
          payload: null,
          created_at: '2026-07-10T08:00:30+00:00',
        },
      ],
    })
    const { container, getByText, unmount } = render(() => <SyncRunSummary run={run} />)

    // Status badge: semantic class + localized text (never colour-only).
    const badge = container.querySelector('.sync-status')
    expect(badge?.className).toContain('is-completed')
    expect(badge?.textContent).toBe('Completed')

    // Counters table: a known key is humanized, an unknown key falls back to its raw key.
    expect(getByText('Created')).toBeTruthy()
    expect(getByText('3')).toBeTruthy()
    expect(getByText('conflicts')).toBeTruthy()
    expect(getByText('1')).toBeTruthy()

    // Item line: kind badge + issue key + reason.
    expect(getByText('ABC-1')).toBeTruthy()
    expect(getByText('both changed')).toBeTruthy()

    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })

  it('renders the failed badge with its class and label', async () => {
    const run = makeRun({ status: 'failed', counters: { errors: 2 } })
    const { container, unmount } = render(() => <SyncRunSummary run={run} />)

    const badge = container.querySelector('.sync-status')
    expect(badge?.className).toContain('is-failed')
    expect(badge?.textContent).toBe('Failed')

    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })

  it('shows a muted dash and no table when there are no counters', async () => {
    const run = makeRun({ counters: {} })
    const { container, unmount } = render(() => <SyncRunSummary run={run} />)

    expect(container.querySelector('.sync-counters')).toBeNull()
    expect(container.querySelector('.sync-counters-empty')?.textContent).toBe('—')

    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })
})
