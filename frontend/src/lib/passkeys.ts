import { browserSupportsWebAuthn, startAuthentication, startRegistration } from '@simplewebauthn/browser'
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

/** Discoverable ("usernameless") passkey login; resolves to the redirect target. */
export async function loginWithPasskey(): Promise<string> {
  const optionsJSON = await postJson<PublicKeyCredentialRequestOptionsJSON>('/login/options', {})
  const assertion = await startAuthentication({ optionsJSON })
  const result = await postJson<{ ok?: boolean; redirect?: string }>('/login/passkey', assertion as unknown as Record<string, unknown>)

  return result.redirect ?? '/'
}

export async function listPasskeys(): Promise<Passkey[]> {
  return (await getJson<{ passkeys: Passkey[] }>('/settings/security/passkeys/list')).passkeys
}

export async function deletePasskey(id: number): Promise<void> {
  await postJson('/settings/security/passkeys/delete', { id })
}
