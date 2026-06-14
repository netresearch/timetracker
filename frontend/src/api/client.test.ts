import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, apiErrorMessage, getJson, postForm, postJson, SessionExpiredError } from './client'

interface FakeResponse {
  ok?: boolean
  status?: number
  redirected?: boolean
  url?: string
  contentType?: string
  body?: string
}

function mockFetch(fake: FakeResponse) {
  const status = fake.status ?? 200
  const response = {
    ok: fake.ok ?? (status >= 200 && status < 300),
    status,
    redirected: fake.redirected ?? false,
    url: fake.url ?? 'http://localhost/x',
    headers: { get: (h: string) => (h.toLowerCase() === 'content-type' ? (fake.contentType ?? 'application/json') : null) },
    json: async () => JSON.parse(fake.body ?? 'null'),
    text: async () => fake.body ?? '',
  }
  const fetchMock = vi.fn().mockResolvedValue(response)
  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

describe('api/client', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  describe('getJson', () => {
    it('appends params, sends Accept JSON, and returns parsed body', async () => {
      const fetchMock = mockFetch({ body: '{"a":1}' })
      const result = await getJson<{ a: number }>('/x', { a: 1, b: 'q' })

      expect(result).toEqual({ a: 1 })
      const url = String(fetchMock.mock.calls[0]![0])
      expect(url).toContain('/x?')
      expect(url).toContain('a=1')
      expect(url).toContain('b=q')
      expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: 'same-origin', headers: { Accept: 'application/json' } })
    })

    it('throws ApiError on a non-ok response', async () => {
      mockFetch({ ok: false, status: 500 })
      await expect(getJson('/x')).rejects.toMatchObject({ name: 'ApiError', status: 500 })
    })

    it('redirects to login when an ok response is not JSON (session expired)', async () => {
      mockFetch({ status: 200, contentType: 'text/html' })
      await expect(getJson('/x')).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it('redirects to login when the request was redirected to /login', async () => {
      mockFetch({ status: 200, redirected: true, url: 'http://localhost/login', contentType: 'application/json' })
      await expect(getJson('/x')).rejects.toBeInstanceOf(SessionExpiredError)
    })
  })

  describe('postForm', () => {
    it('url-encodes the body and posts as form-urlencoded', async () => {
      const fetchMock = mockFetch({ body: 'ok' })
      const result = await postForm('/p', { a: 1, b: 'x y' })

      expect(result).toBe('ok')
      const init = fetchMock.mock.calls[0]![1]
      expect(init.method).toBe('POST')
      expect(init.headers['Content-Type']).toBe('application/x-www-form-urlencoded')
      expect(String(init.body)).toBe('a=1&b=x+y')
    })

    it('throws ApiError carrying the response body on non-ok (the 422 contract)', async () => {
      mockFetch({ ok: false, status: 422, body: 'Start must be before end' })
      await expect(postForm('/p', {})).rejects.toMatchObject({ status: 422, message: 'Start must be before end' })
    })

    it('falls back to a status message when the error body is empty', async () => {
      mockFetch({ ok: false, status: 500, body: '' })
      await expect(postForm('/p', {})).rejects.toMatchObject({ message: '/p: HTTP 500' })
    })
  })

  describe('postJson', () => {
    it('sends a JSON body and parses the JSON response', async () => {
      const fetchMock = mockFetch({ body: '{"id":7}' })
      const result = await postJson<{ id: number }>('/s', { name: 'x', active: true })

      expect(result).toEqual({ id: 7 })
      const init = fetchMock.mock.calls[0]![1]
      expect(init.headers['Content-Type']).toBe('application/json')
      expect(init.body).toBe('{"name":"x","active":true}')
    })

    it('returns null for an empty ok body', async () => {
      mockFetch({ status: 200, body: '' })
      await expect(postJson('/s', {})).resolves.toBeNull()
    })

    it('surfaces the {message} envelope from a non-ok JSON body', async () => {
      mockFetch({ ok: false, status: 409, body: '{"message":"Customer in use"}' })
      await expect(postJson('/s', {})).rejects.toMatchObject({ status: 409, message: 'Customer in use' })
    })

    it('surfaces a plain-text non-ok body verbatim', async () => {
      mockFetch({ ok: false, status: 422, body: 'business rule violated' })
      await expect(postJson('/s', {})).rejects.toMatchObject({ message: 'business rule violated' })
    })
  })

  describe('apiErrorMessage', () => {
    it('returns the ApiError message, else the fallback', () => {
      expect(apiErrorMessage(new ApiError(400, 'nope'), 'fb')).toBe('nope')
      expect(apiErrorMessage(new Error('other'), 'fb')).toBe('fb')
      expect(apiErrorMessage('weird', 'fb')).toBe('fb')
    })
  })
})
