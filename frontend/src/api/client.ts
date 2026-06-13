export class SessionExpiredError extends Error {
  constructor() {
    super('Session expired — redirecting to login')
    this.name = 'SessionExpiredError'
  }
}

/** A non-2xx response whose body is a (plain-text or JSON) error message. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

function redirectToLogin(): never {
  window.location.assign('/login')
  throw new SessionExpiredError()
}

// The backend answers expired sessions with a 302 to /login (HTML) instead of
// a 401 — fetch follows the redirect silently, so we detect the login page by
// its URL/content type and send the user there for a full page login.
function landedOnLogin(response: Response): boolean {
  return response.redirected && new URL(response.url).pathname.startsWith('/login')
}

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

  const contentType = response.headers.get('content-type') ?? ''
  if (landedOnLogin(response) || (response.ok && !contentType.includes('json'))) {
    redirectToLogin()
  }

  if (!response.ok) {
    throw new ApiError(response.status, `${path}: HTTP ${response.status}`)
  }

  return response.json() as Promise<T>
}

/**
 * POSTs application/x-www-form-urlencoded — the shape every legacy data
 * endpoint reads ($request->request, no #[MapRequestPayload]). Returns the raw
 * text body so callers can handle both JSON ({success,...}) and plain-text
 * (bulk-entry) contracts; HTTP 422 carries the validation message as the body.
 */
export async function postForm(
  path: string,
  params: Record<string, string | number>,
): Promise<string> {
  const body = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    body.set(key, String(value))
  }

  const response = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    credentials: 'same-origin',
    body,
  })

  if (landedOnLogin(response)) {
    redirectToLogin()
  }

  const text = await response.text()
  if (!response.ok) {
    throw new ApiError(response.status, text || `${path}: HTTP ${response.status}`)
  }

  return text
}
