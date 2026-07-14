import { Dialog } from '@ark-ui/solid/dialog'
import { createEffect, createSignal, onCleanup, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import { ApiError } from '../api/client'
import { appConfig } from '../config'
import { PasskeyIcon } from '../lib/icons'
import { cancelPasskeyCeremony, loginWithPasskey, loginWithPasskeyAutofill, passkeyAutofillSupported, passkeysSupported } from '../lib/passkeys'
import { m } from '../paraglide/messages.js'

// The firewall-intercepted 2FA code path. APP_CONFIG (unlike LOGIN_CONFIG) does
// not carry it, and the route is stable (config/routes/scheb_2fa.yaml), so it is
// referenced directly here — the same literal LoginForm falls back to.
const TWO_FACTOR_PATH = '/2fa_check'

/**
 * Shown when the backend session is lost (see lib/session). A non-dismissible
 * modal over the dimmed, inert page: the user re-authenticates in place and the
 * SPA resumes exactly where it was — nothing navigates or unmounts, so the
 * in-progress work (worklog drafts, new rows, the bulk form) survives (issue #408).
 *
 * Full parity with the /login LoginForm, minus the navigation: a password step,
 * the ADR-018 second-factor code step (a correct password on an enrolled account
 * answers {twoFactorRequired:true} — post the TOTP/backup code to /2fa_check), and
 * a "Sign in with a passkey" button (a passkey is inherently MFA, so it completes
 * in one step). Every success calls onSuccess (dismiss + refetch) instead of
 * redirecting. The 'authenticate' CSRF token is stateless, so the page-load token
 * in APP_CONFIG is still valid after expiry.
 */
export function SessionExpiredOverlay(props: { onSuccess: () => void }) {
  const cfg = appConfig()
  const [username, setUsername] = createSignal(cfg.userName)
  const [password, setPassword] = createSignal('')
  const [code, setCode] = createSignal('')
  const [phase, setPhase] = createSignal<'credentials' | 'code'>('credentials')
  const [error, setError] = createSignal<string | null>(null)
  const [submitting, setSubmitting] = createSignal(false)
  let passwordEl: HTMLInputElement | undefined

  // X-Requested-With is what makes the authenticator (and the scheb 2FA handlers)
  // answer with JSON instead of a 302; credentials keeps the session/CSRF cookies.
  const postForm = (path: string, body: Record<string, string>) => fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    },
    body: new URLSearchParams(body),
  })

  const submit = async (event: SubmitEvent) => {
    event.preventDefault()
    if (submitting()) {
      return
    }
    if (username().trim() === '' || password() === '') {
      setError(m.login_required())

      return
    }
    setSubmitting(true)
    setError(null)
    try {
      // No _remember_me here: the overlay only appears when no valid REMEMBERME
      // cookie rescued the session (a remembered session passes the ^/ rule,
      // #587), so there is no cookie to preserve — and a re-login must not
      // grant a 30-day cookie the user never opted into. Opting in stays on
      // the full login form's checkbox.
      const response = await postForm(cfg.loginPath, {
        _username: username(),
        _password: password(),
        _csrf_token: cfg.csrfToken,
      })
      const data = (await response.json().catch(() => null)) as { ok?: boolean; twoFactorRequired?: boolean } | null
      if (response.ok && data?.ok === true) {
        props.onSuccess()

        return
      }
      if (data?.twoFactorRequired === true) {
        // Password accepted; the account needs its second factor. Swap to the
        // code step (mirrors LoginForm) — NOT a credentials failure.
        setPhase('code')
        setPassword('')

        return
      }
      setError(m.login_error())
      setPassword('')
      passwordEl?.focus()
    } catch {
      setError(m.login_error())
    } finally {
      setSubmitting(false)
    }
  }

  const submitCode = async (event: SubmitEvent) => {
    event.preventDefault()
    if (submitting()) {
      return
    }
    if (code().trim() === '') {
      setError(m.login_2fa_error())

      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const response = await postForm(TWO_FACTOR_PATH, { _auth_code: code().trim() })
      const data = (await response.json().catch(() => null)) as { ok?: boolean } | null
      if (response.ok && data?.ok === true) {
        props.onSuccess()

        return
      }
      setError(m.login_2fa_error())
    } catch {
      setError(m.login_2fa_error())
    } finally {
      setSubmitting(false)
    }
  }

  const signInWithPasskey = async () => {
    if (submitting()) {
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      // A passkey is inherently MFA, so this re-establishes a fully-authenticated
      // session in one step. Resume in place (ignore the returned redirect).
      // remember=false mirrors the overlay's password path: the overlay only
      // shows when no valid REMEMBERME cookie exists (#587), so there is
      // nothing to preserve and nothing to silently grant.
      await loginWithPasskey(() => false)
      props.onSuccess()
    } catch {
      // Includes the user dismissing the native prompt — a quiet inline message.
      setError(m.login_passkey_error())
    } finally {
      setSubmitting(false)
    }
  }

  const backToCredentials = () => {
    setPhase('credentials')
    setCode('')
    setError(null)
  }

  // Conditional UI (passkey autofill), same as LoginForm but keyed on the
  // credentials phase so it (re)starts on mount AND when returning from the 2FA
  // step, with a FRESH AbortController each time (an aborted one can't be reused).
  // The 'webauthn' token on the username field enables the inline surfacing; a
  // picked passkey resumes in place (onSuccess) instead of navigating. Leaving the
  // phase (→ 2FA) or unmounting tears it down — abort stops it in the
  // /login/options window, cancelPasskeyCeremony aborts a started
  // navigator.credentials.get() — so a late pick can't resume mid-2FA or after the
  // overlay closes. Ceremony rejections (unsupported, dismissed, aborted) are
  // silent; only a server rejection of a selected passkey surfaces an error.
  createEffect(() => {
    if (phase() !== 'credentials') {
      return
    }
    const passkeyAutofill = new AbortController()
    void (async () => {
      try {
        if (!(await passkeyAutofillSupported())) {
          return
        }
        await loginWithPasskeyAutofill(() => false, passkeyAutofill.signal)
        props.onSuccess()
      } catch (caught) {
        if (caught instanceof ApiError) {
          setError(m.login_passkey_error())
        }
      }
    })()
    onCleanup(() => {
      passkeyAutofill.abort()
      cancelPasskeyCeremony()
    })
  })

  return (
    // modal (default) inerts the page below (readable but non-interactive for AT);
    // non-dismissible (no Esc / outside-click close) — only a successful re-login
    // closes it. Focus lands on the password (username is pre-filled).
    <Dialog.Root open role="alertdialog" closeOnEscape={false} closeOnInteractOutside={false} initialFocusEl={() => passwordEl ?? null}>
      <Portal>
        <Dialog.Backdrop class="modal-backdrop session-backdrop" />
        <Dialog.Positioner class="modal-positioner session-positioner">
          <Dialog.Content class="modal session-card">
            <Dialog.Title class="session-title">{m.session_expired_title()}</Dialog.Title>
            <Dialog.Description class="session-desc">{m.session_expired_body()}</Dialog.Description>

            <Show when={error()}>
              <p class="form-status is-error" role="alert">{error()}</p>
            </Show>

            <Show
              when={phase() === 'credentials'}
              fallback={
                <form class="session-form" onSubmit={(event) => void submitCode(event)} novalidate>
                  <p class="login-2fa-hint">{m.login_2fa_hint()}</p>

                  <label class="field">
                    <span>{m.login_2fa_code()}</span>
                    <input
                      id="session-auth-code"
                      type="text"
                      name="_auth_code"
                      autocomplete="one-time-code"
                      autocapitalize="off"
                      autocorrect="off"
                      spellcheck={false}
                      required
                      // Backup codes are hex, so no inputmode=numeric. Ref-focus
                      // because this phase mounts dynamically (no document load).
                      ref={(el) => setTimeout(() => el.focus())}
                      value={code()}
                      onInput={(e) => setCode(e.currentTarget.value)}
                    />
                  </label>

                  <button type="submit" class="primary-button session-submit" disabled={submitting()}>
                    {submitting() ? m.login_submitting() : m.login_2fa_submit()}
                  </button>
                  <button type="button" class="login-2fa-back" onClick={backToCredentials}>
                    {m.login_2fa_back()}
                  </button>
                </form>
              }
            >
              <form class="session-form" onSubmit={(event) => void submit(event)} novalidate>
                <label class="field">
                  <span>{m.login_username()}</span>
                  <input
                    type="text"
                    name="_username"
                    // 'webauthn' surfaces the user's passkeys inline in this field's
                    // autofill — backed by the conditional-mediation ceremony started
                    // on mount above (matching the /login form).
                    autocomplete="username webauthn"
                    autocapitalize="off"
                    autocorrect="off"
                    spellcheck={false}
                    required
                    value={username()}
                    onInput={(e) => setUsername(e.currentTarget.value)}
                  />
                </label>

                <label class="field">
                  <span>{m.login_password()}</span>
                  <input
                    ref={(el) => {
                      passwordEl = el
                      // Re-focus on (re)mount — notably when returning from the 2FA
                      // step to this phase, where Dialog's initialFocusEl no longer
                      // fires. Defer a tick and guard isConnected in case the phase
                      // flips back before the callback runs.
                      setTimeout(() => { if (el.isConnected) el.focus() })
                    }}
                    type="password"
                    name="_password"
                    autocomplete="current-password"
                    required
                    value={password()}
                    onInput={(e) => setPassword(e.currentTarget.value)}
                  />
                </label>

                <button type="submit" class="primary-button session-submit" disabled={submitting()}>
                  {submitting() ? m.login_submitting() : m.login_submit()}
                </button>

                <Show when={passkeysSupported()}>
                  <button type="button" class="login-passkey" disabled={submitting()} onClick={() => void signInWithPasskey()}>
                    {/* No whitespace text node between the two children: the button
                        is a flex row with gap, so a stray space would become a
                        third flex item and double the gap. */}
                    <PasskeyIcon /><span>{m.login_passkey()}</span>
                  </button>
                </Show>
              </form>
            </Show>

            {/* Escape hatch: full-page login (reload) for a persistent failure or
                a different user — the safe path when an in-place resume can't apply. */}
            <a class="session-fallback" href={cfg.loginPath}>{m.session_expired_fallback()}</a>
          </Dialog.Content>
        </Dialog.Positioner>
      </Portal>
    </Dialog.Root>
  )
}
