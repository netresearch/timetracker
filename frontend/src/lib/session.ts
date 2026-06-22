import { createSignal } from 'solid-js'

/**
 * App-wide "the backend session is gone" state. A module-level signal is a
 * singleton that both the API client (a plain module that sets it on a 401 /
 * redirect-to-login) and the app shell (which renders the in-place re-login
 * overlay) can reach without threading a context through the tree.
 */
export const [sessionExpired, setSessionExpired] = createSignal(false)

// Idle-tab probe cadence. The probe also runs on every tab refocus, so this is
// only the "user left the tab open and idle" path.
const POLL_MS = 60_000

/**
 * Probe the PUBLIC `/status/check` endpoint, which returns `{loginStatus: bool}`
 * with a 200 whether or not you are authenticated — so it never itself trips the
 * redirect-to-login detection, and it lets us surface a SILENT expiry (the user
 * idle on a page) before they try to act. A failed/again-network probe must never
 * raise the overlay: a real expiry is still caught reactively on the next data call.
 * Safe to run even while the overlay is up — setSessionExpired(true) is idempotent.
 */
async function probeSession(): Promise<void> {
  try {
    const response = await fetch('/status/check', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (!response.ok) {
      return // transient server issue — don't lock the user out on a blip
    }
    const data = (await response.json()) as { loginStatus?: boolean }
    if (data.loginStatus === false) {
      setSessionExpired(true)
    }
  } catch {
    // Network error (offline, etc.) — ignore; reactive detection still covers a
    // genuinely expired session on the next data request.
  }
}

/**
 * Surface a silently-expired session: probe on every tab refocus and on a gentle
 * interval while the tab is visible. Returns a disposer for the app shell to call
 * on cleanup.
 */
export function startSessionMonitor(): () => void {
  const onVisible = (): void => {
    if (document.visibilityState === 'visible') {
      void probeSession()
    }
  }
  document.addEventListener('visibilitychange', onVisible)
  const timer = window.setInterval(onVisible, POLL_MS)

  return () => {
    document.removeEventListener('visibilitychange', onVisible)
    window.clearInterval(timer)
  }
}
