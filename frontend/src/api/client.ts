export class SessionExpiredError extends Error {
  constructor() {
    super('Session expired — redirecting to login')
    this.name = 'SessionExpiredError'
  }
}

// The backend answers expired sessions with a 302 to /login (HTML) instead of
// a 401 — fetch follows the redirect silently, so we detect the login page by
// its URL/content type and send the user there for a full page login.
export async function getJson<T>(
  path: string,
  params: Record<string, string | number> = {},
): Promise<T> {
  const url = new URL(path, window.location.origin)
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, String(value))
  }

  const response = await fetch(url, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  })

  const landedOnLogin = response.redirected && new URL(response.url).pathname.startsWith('/login')
  const contentType = response.headers.get('content-type') ?? ''
  if (landedOnLogin || (response.ok && !contentType.includes('json'))) {
    window.location.assign('/login')
    throw new SessionExpiredError()
  }

  if (!response.ok) {
    throw new Error(`${path}: HTTP ${response.status}`)
  }

  return response.json() as Promise<T>
}
