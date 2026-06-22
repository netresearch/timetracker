import { afterEach, describe, expect, it, vi } from 'vitest'

import { sessionExpired, setSessionExpired, startSessionMonitor } from './session'

afterEach(() => {
  setSessionExpired(false)
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

// jsdom reports document.visibilityState as 'visible', so a visibilitychange
// event drives the probe.
function probeOnRefocus(loginStatus: boolean): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ loginStatus }) })
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

describe('startSessionMonitor', () => {
  it('raises sessionExpired when /status/check reports loginStatus:false on tab refocus', async () => {
    const fetchMock = probeOnRefocus(false)
    const dispose = startSessionMonitor()
    document.dispatchEvent(new Event('visibilitychange'))

    await vi.waitFor(() => expect(sessionExpired()).toBe(true))
    expect(String(fetchMock.mock.calls[0]![0])).toBe('/status/check')
    dispose()
  })

  it('leaves the session alone when loginStatus is true', async () => {
    const fetchMock = probeOnRefocus(true)
    const dispose = startSessionMonitor()
    document.dispatchEvent(new Event('visibilitychange'))

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(sessionExpired()).toBe(false)
    dispose()
  })

  it('never raises on a failed probe (offline / server error)', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('offline'))
    vi.stubGlobal('fetch', fetchMock)
    const dispose = startSessionMonitor()
    document.dispatchEvent(new Event('visibilitychange'))

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
    expect(sessionExpired()).toBe(false)
    dispose()
  })
})
