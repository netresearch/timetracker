import { afterEach, describe, expect, it, vi } from 'vitest'

const getJson = vi.fn()
const postJson = vi.fn()

// Mock the API client so the real query-factory / write-helper bodies run and we
// can assert the exact path + params + body each one hands to the transport.
vi.mock('./client', () => ({
  getJson: (...args: unknown[]) => getJson(...args),
  postJson: (...args: unknown[]) => postJson(...args),
}))

const {
  createSyncRun,
  resolveConflict,
  syncConflictsQuery,
  syncRunQuery,
  syncRunsQuery,
  worklogSyncKeys,
} = await import('./worklogSync')

describe('syncRunsQuery', () => {
  afterEach(() => vi.clearAllMocks())

  it('lists runs with no params by default', () => {
    const q = syncRunsQuery()
    expect(q.queryKey).toEqual(['worklog-sync', 'runs', {}])
    void q.queryFn()
    expect(getJson).toHaveBeenCalledWith('/api/v2/worklog-sync/runs', {})
  })

  it('passes ticket_system_id and limit through', () => {
    const q = syncRunsQuery(3, 5)
    expect(q.queryKey).toEqual(['worklog-sync', 'runs', { ticket_system_id: 3, limit: 5 }])
    void q.queryFn()
    expect(getJson).toHaveBeenCalledWith('/api/v2/worklog-sync/runs', { ticket_system_id: 3, limit: 5 })
  })
})

describe('syncRunQuery', () => {
  afterEach(() => vi.clearAllMocks())

  it('fetches a single run by id', () => {
    const q = syncRunQuery(7)
    expect(q.queryKey).toEqual(['worklog-sync', 'runs', 7])
    void q.queryFn()
    expect(getJson).toHaveBeenCalledWith('/api/v2/worklog-sync/runs/7')
  })
})

describe('syncConflictsQuery', () => {
  afterEach(() => vi.clearAllMocks())

  it('scopes to a user when given one', () => {
    const q = syncConflictsQuery('jdoe')
    expect(q.queryKey).toEqual(['worklog-sync', 'conflicts', { user: 'jdoe' }])
    void q.queryFn()
    expect(getJson).toHaveBeenCalledWith('/api/v2/worklog-sync/conflicts', { user: 'jdoe' })
  })

  it('omits the user param when unscoped', () => {
    const q = syncConflictsQuery()
    expect(q.queryKey).toEqual(['worklog-sync', 'conflicts', {}])
    void q.queryFn()
    expect(getJson).toHaveBeenCalledWith('/api/v2/worklog-sync/conflicts', {})
  })
})

describe('worklogSyncKeys', () => {
  it('exposes stable base keys the caller invalidates on', () => {
    expect(worklogSyncKeys.runs).toEqual(['worklog-sync', 'runs'])
    expect(worklogSyncKeys.conflicts).toEqual(['worklog-sync', 'conflicts'])
  })
})

describe('createSyncRun', () => {
  afterEach(() => vi.clearAllMocks())

  it('posts the payload verbatim and returns the created run', async () => {
    postJson.mockResolvedValueOnce({ id: 42 })
    const run = await createSyncRun({ type: 'verify', ticket_system_id: 1 })
    expect(postJson).toHaveBeenCalledWith('/api/v2/worklog-sync/runs', { type: 'verify', ticket_system_id: 1 })
    expect(run).toEqual({ id: 42 })
  })

  it('carries the optional import fields', async () => {
    postJson.mockResolvedValueOnce({ id: 7 })
    await createSyncRun({
      type: 'import',
      ticket_system_id: 2,
      from: '2026-07-01',
      to: '2026-07-31',
      users: ['jdoe'],
      default_activity_id: 5,
      dry_run: true,
    })
    expect(postJson).toHaveBeenCalledWith('/api/v2/worklog-sync/runs', {
      type: 'import',
      ticket_system_id: 2,
      from: '2026-07-01',
      to: '2026-07-31',
      users: ['jdoe'],
      default_activity_id: 5,
      dry_run: true,
    })
  })
})

describe('resolveConflict', () => {
  afterEach(() => vi.clearAllMocks())

  it('posts the winner to the per-conflict resolve endpoint', async () => {
    postJson.mockResolvedValueOnce({ resolved: true, action: 'kept-local', conflict_id: 5 })
    const result = await resolveConflict(5, 'local')
    expect(postJson).toHaveBeenCalledWith('/api/v2/worklog-sync/conflicts/5/resolve', { winner: 'local' })
    expect(result).toEqual({ resolved: true, action: 'kept-local', conflict_id: 5 })
  })

  it('supports the remote winner', async () => {
    postJson.mockResolvedValueOnce({ resolved: true, action: 'kept-remote', conflict_id: 9 })
    await resolveConflict(9, 'remote')
    expect(postJson).toHaveBeenCalledWith('/api/v2/worklog-sync/conflicts/9/resolve', { winner: 'remote' })
  })
})
