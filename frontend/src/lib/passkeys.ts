import { browserSupportsWebAuthn, browserSupportsWebAuthnAutofill, startAuthentication, startRegistration, WebAuthnAbortService } from '@simplewebauthn/browser'
import type { PublicKeyCredentialCreationOptionsJSON, PublicKeyCredentialRequestOptionsJSON } from '@simplewebauthn/browser'

import { getJson, postJson } from '../api/client'

/** One of the current user's registered passkeys (from the list endpoint). */
export interface Passkey {
  id: number
  fingerprint: string
  transports: string[]
}

/** Whether this browser can do WebAuthn at all (hide the passkey UI otherwise). */
export const passkeysSupported = browserSupportsWebAuthn

/**
 * Register a passkey for the logged-in user: fetch creation options, run the
 * browser attestation ceremony, post the result. The server persists the
 * credential and returns {success:true}. Throws (incl. the user cancelling the
 * native prompt) — callers surface the message.
 */
export async function registerPasskey(): Promise<void> {
  const optionsJSON = await postJson<PublicKeyCredentialCreationOptionsJSON>('/settings/security/passkeys/options', {})
  const attestation = await startRegistration({ optionsJSON })
  // The attestation is the standard PublicKeyCredential JSON the ceremony
  // controller deserializes; cast past postJson's Record signature.
  await postJson('/settings/security/passkeys', attestation as unknown as Record<string, unknown>)
}

/** Whether the browser can surface passkeys inline via autofill (Conditional UI). */
export const passkeyAutofillSupported = browserSupportsWebAuthnAutofill

function abortedError(): DOMException {
  return new DOMException('Passkey ceremony aborted.', 'AbortError')
}

/**
 * Discoverable ("usernameless") passkey login. `useBrowserAutofill` picks the
 * mediation: false = an immediate native modal (the explicit button); true =
 * Conditional UI, which resolves only once the user selects a passkey from the
 * username field's autofill. Both post the assertion to /login/passkey and
 * resolve to the redirect target.
 *
 * `signal` closes the SPA race the background (autofill) caller has: the phase
 * can switch or the form unmount DURING the /login/options fetch, before any
 * WebAuthn ceremony exists for cancelPasskeyCeremony() to abort. Checking it
 * around that await stops startAuthentication from firing a stray prompt after
 * the caller has moved on.
 */
async function requestPasskeyLogin(useBrowserAutofill: boolean, signal?: AbortSignal): Promise<string> {
  if (signal?.aborted) {
    throw abortedError()
  }
  const optionsJSON = await postJson<PublicKeyCredentialRequestOptionsJSON>('/login/options', {})
  if (signal?.aborted) {
    throw abortedError()
  }
  const assertion = await startAuthentication({ optionsJSON, useBrowserAutofill })
  const result = await postJson<{ ok?: boolean; redirect?: string }>('/login/passkey', assertion as unknown as Record<string, unknown>)

  return result.redirect ?? '/'
}

/** Explicit ("Sign in with a passkey" button) modal login. */
export function loginWithPasskey(): Promise<string> {
  return requestPasskeyLogin(false)
}

/**
 * Conditional-UI ("autofill") passkey login, started in the background from
 * page load. Resolves to a redirect once the user picks a passkey from the
 * username field's autofill; rejects if aborted (via `signal` before the
 * ceremony, or {@see cancelPasskeyCeremony} during it) or on error — callers
 * stay quiet on the ceremony rejections and surface only a server rejection.
 */
export function loginWithPasskeyAutofill(signal?: AbortSignal): Promise<string> {
  return requestPasskeyLogin(true, signal)
}

/**
 * Cancel any in-flight passkey ceremony. The background autofill request must
 * not outlive the login form's credentials phase — otherwise it could resolve
 * and redirect the user mid-2FA. A NEW startAuthentication (the explicit button)
 * already aborts a prior ceremony on its own; this is for the paths that don't
 * start one (leaving the credentials phase, unmounting).
 */
export function cancelPasskeyCeremony(): void {
  WebAuthnAbortService.cancelCeremony()
}

export async function listPasskeys(): Promise<Passkey[]> {
  return (await getJson<{ passkeys: Passkey[] }>('/settings/security/passkeys/list')).passkeys
}

export async function deletePasskey(id: number): Promise<void> {
  await postJson('/settings/security/passkeys/delete', { id })
}
