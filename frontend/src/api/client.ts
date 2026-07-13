import { setSessionExpired } from '../lib/session'

export class SessionExpiredError extends Error {
  constructor() {
    super('Session expired — re-login required')
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

/** A client-side validation failure whose message is already a user-facing,
 *  localized string (e.g. start ≥ end) and should be shown verbatim — unlike a
 *  raw Error, whose message may be technical (and must fall back instead). */
export class ValidationError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ValidationError'
  }
}

/** The message from an ApiError or a client-side ValidationError (both already
 *  user-facing), or a fallback for any other (possibly technical) failure. */
export function apiErrorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError || error instanceof ValidationError ? error.message : fallback
}

// Raise the app-wide session-expired state — the shell shows an in-place re-login
// overlay over the dimmed page — instead of navigating to /login. A full redirect
// would unmount the SPA and discard the user's in-progress work (issue #408). The
// throw still rejects the in-flight call so callers don't proceed on null data and
// the query retry guard (App.tsx) halts retries while the overlay is up.
function raiseSessionExpired(): never {
  setSessionExpired(true)
  throw new SessionExpiredError()
}

// A lost session surfaces two ways: the firewall 302s an expired data request to
// /login (HTML) — fetch follows it silently, detected here by the landed-on URL —
// or an endpoint answers 401 directly (BaseController::getFailedLoginResponse,
// "You need to login"). Both mean the same thing: re-login required.
function landedOnLogin(response: Response): boolean {
  return response.redirected && new URL(response.url).pathname.startsWith('/login')
}

function sessionLost(response: Response): boolean {
  return landedOnLogin(response) || response.status === 401
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
  if (sessionLost(response) || (response.ok && !contentType.includes('json'))) {
    raiseSessionExpired()
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

  if (sessionLost(response)) {
    raiseSessionExpired()
  }

  const text = await response.text()
  if (!response.ok) {
    throw new ApiError(response.status, text || `${path}: HTTP ${response.status}`)
  }

  return text
}

/**
 * POSTs a typed JSON body — the admin save/delete endpoints bind it via
 * Symfony #[MapRequestPayload], which keeps booleans/arrays/ints typed (form
 * encoding would flatten them). On a non-2xx the body is either an
 * App\Response\Error envelope ({message}) or a plain-text business-rule
 * message; both are surfaced as ApiError.message.
 */
export function postJson<T = unknown>(
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  return sendJson<T>('POST', path, payload)
}

/**
 * PUTs a typed JSON body — the idempotent counterpart to {@link postJson} for
 * the v2 endpoints that model a set/replace (e.g. the worklog-sync preferences).
 * Same #[MapRequestPayload] binding and error contract as postJson.
 */
export function putJson<T = unknown>(
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  return sendJson<T>('PUT', path, payload)
}

/**
 * PATCHes a typed JSON body — partial updates on the v2 endpoints
 * ("not sent = unchanged", e.g. /api/v2/settings). Same #[MapRequestPayload]
 * binding and error contract as postJson.
 */
export function patchJson<T = unknown>(
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  return sendJson<T>('PATCH', path, payload)
}

async function sendJson<T>(
  method: 'POST' | 'PUT' | 'PATCH',
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  const response = await fetch(path, {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  })

  if (sessionLost(response)) {
    raiseSessionExpired()
  }

  const text = await response.text()
  if (!response.ok) {
    let message = text
    try {
      const parsed = JSON.parse(text) as { message?: string }
      if (typeof parsed.message === 'string') {
        message = parsed.message
      }
    } catch {
      // Plain-text body — use it verbatim.
    }
    throw new ApiError(response.status, message || `${path}: HTTP ${response.status}`)
  }

  return (text ? JSON.parse(text) : null) as T
}

/**
 * POSTs multipart/form-data (a file upload and/or plain fields) — the browser
 * sets the Content-Type with boundary, so it is left off here. Reads
 * `$request->files`/`$request->request` server-side. Returns the raw text body
 * (JSON envelope or plain-text message), like postForm.
 */
export async function postMultipart(path: string, form: FormData): Promise<string> {
  const response = await fetch(path, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    body: form,
  })

  if (sessionLost(response)) {
    raiseSessionExpired()
  }

  const text = await response.text()
  if (!response.ok) {
    let message = text
    try {
      const parsed = JSON.parse(text) as { message?: string }
      if (typeof parsed.message === 'string') {
        message = parsed.message
      }
    } catch {
      // Plain-text body — use it verbatim.
    }
    throw new ApiError(response.status, message || `${path}: HTTP ${response.status}`)
  }

  return text
}
