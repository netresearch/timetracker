import { createEffect, createSignal, onCleanup, onMount, Show } from 'solid-js'

import { ApiError } from '../api/client'
import type { LoginConfig } from '../loginConfig'
import { PasskeyIcon } from '../lib/icons'
import { cancelPasskeyCeremony, loginWithPasskey, loginWithPasskeyAutofill, passkeyAutofillSupported, passkeysSupported } from '../lib/passkeys'
import { m } from '../paraglide/messages.js'

/**
 * SolidJS login form. Renders a real <form> that posts to the Symfony `_login`
 * route, so it works without JS (progressive enhancement); when JS is active it
 * submits via fetch (X-Requested-With) and shows inline errors without a reload.
 * The field names (_username, _password, _csrf_token, _remember_me) and the
 * #form-submit id match what the firewall and the e2e suite expect.
 *
 * Two-factor (ADR-018 D2): a correct password on an enrolled account answers
 * {twoFactorRequired:true}, and the form swaps to a one-field code step posting
 * `_auth_code` to /2fa_check (a TOTP code or a one-time backup code). The no-JS
 * fallback is redirected server-side to the /2fa form instead.
 */
export function LoginForm(props: { config: LoginConfig }) {
  // The config is injected once at page load and never changes, so reading it
  // for the signals' initial values is a deliberate one-time read.
  // eslint-disable-next-line solid/reactivity
  const cfg = props.config
  const [username, setUsername] = createSignal(cfg.lastUsername)
  const [password, setPassword] = createSignal('')
  const [remember, setRemember] = createSignal(true)
  const [error, setError] = createSignal<string | null>(cfg.error)
  const [submitting, setSubmitting] = createSignal(false)
  const [phase, setPhase] = createSignal<'credentials' | 'code'>('credentials')
  const [code, setCode] = createSignal('')

  const postForm = (path: string, body: Record<string, string>) => fetch(path, {
    method: 'POST',
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
      const response = await postForm(props.config.loginPath, {
        _username: username(),
        _password: password(),
        _csrf_token: props.config.csrfToken,
        ...(remember() ? { _remember_me: 'on' } : {}),
      })
      const data = (await response.json().catch(() => null)) as { ok?: boolean; redirect?: string; twoFactorRequired?: boolean } | null
      if (response.ok && data?.ok === true) {
        globalThis.location.assign(data.redirect ?? '/')

        return
      }
      if (data?.twoFactorRequired === true) {
        // Password accepted; the account needs its second factor.
        setPhase('code')

        return
      }
      setError(m.login_error())
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
      // novalidate disables the browser's own message — say why nothing happened.
      setError(m.login_2fa_error())

      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const response = await postForm(props.config.twoFactorPath ?? '/2fa_check', {
        _auth_code: code().trim(),
      })
      const data = (await response.json().catch(() => null)) as { ok?: boolean; redirect?: string } | null
      if (response.ok && data?.ok === true) {
        globalThis.location.assign(data.redirect ?? '/')

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
      globalThis.location.assign(await loginWithPasskey(remember))
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

  // Conditional UI (passkey autofill): from page load, quietly ask the browser
  // to offer the user's discoverable passkeys in the username field's autofill.
  // It resolves to a redirect once the user picks one — no button, no modal
  // until they engage. Ceremony rejections (unsupported, dismissed, or aborted
  // by the explicit button starting its own) are intentionally silent; only a
  // server rejection of a passkey the user actually selected surfaces an error.
  // Owns the background autofill request so it can be torn down when the form
  // leaves the credentials phase or unmounts.
  const passkeyAutofill = new AbortController()

  onMount(() => {
    void (async () => {
      try {
        if (!(await passkeyAutofillSupported())) {
          return
        }
        globalThis.location.assign(await loginWithPasskeyAutofill(remember, passkeyAutofill.signal))
      } catch (caught) {
        // An ApiError means the ceremony produced an assertion but the server
        // rejected it — worth telling the user. Everything else (abort, dismiss,
        // unsupported, and the in-try support probe rejecting) stays silent so
        // the password form is never disrupted.
        if (caught instanceof ApiError) {
          setError(m.login_passkey_error())
        }
      }
    })()
  })

  // Tear the autofill request down completely: the AbortController stops it in
  // the /login/options window (before any ceremony exists), and cancelCeremony
  // aborts an already-started navigator.credentials.get(). A password submit is
  // a plain fetch and aborts neither on its own, so do both when the form
  // switches to the 2FA code step (else a late selection could redirect
  // mid-2FA) and on unmount.
  const teardownPasskeyAutofill = (): void => {
    passkeyAutofill.abort()
    cancelPasskeyCeremony()
  }
  createEffect(() => {
    if (phase() === 'code') {
      teardownPasskeyAutofill()
    }
  })
  onCleanup(teardownPasskeyAutofill)

  return (
    <main class="login-page">
      <Show
        when={phase() === 'credentials'}
        fallback={
          <form class="login-card" onSubmit={(event) => void submitCode(event)} novalidate>
            <img class="login-logo" src={props.config.logoUrl} alt={props.config.appTitle} />
            <h1 class="login-title">{props.config.appTitle}</h1>

            <Show when={error()}>
              <p class="form-status is-error" role="alert">
                {error()}
              </p>
            </Show>

            <p class="login-2fa-hint">{m.login_2fa_hint()}</p>

            <label class="field">
              <span>{m.login_2fa_code()}</span>
              <input
                id="auth-code"
                type="text"
                name="_auth_code"
                autocomplete="one-time-code"
                autocapitalize="off"
                autocorrect="off"
                spellcheck={false}
                required
                // No inputmode=numeric: backup codes are hex, so mobile users
                // need the full keyboard. Ref-focus because autofocus only fires
                // on document load, not on this dynamically mounted phase.
                ref={(el) => setTimeout(() => el.focus())}
                value={code()}
                onInput={(e) => setCode(e.currentTarget.value)}
              />
            </label>

            <button type="submit" id="form-submit" class="primary-button login-submit" disabled={submitting()}>
              {submitting() ? m.login_submitting() : m.login_2fa_submit()}
            </button>
            <button type="button" class="login-2fa-back" onClick={backToCredentials}>
              {m.login_2fa_back()}
            </button>
          </form>
        }
      >
        <form class="login-card" method="post" action={props.config.loginPath} onSubmit={(event) => void submit(event)} novalidate>
          <img class="login-logo" src={props.config.logoUrl} alt={props.config.appTitle} />
          <h1 class="login-title">{props.config.appTitle}</h1>

          <Show when={error()}>
            <p class="form-status is-error" role="alert">
              {error()}
            </p>
          </Show>

          <input type="hidden" name="_csrf_token" value={props.config.csrfToken} />

          <label class="field">
            <span>{m.login_username()}</span>
            <input
              type="text"
              name="_username"
              // The 'webauthn' token is what lets the browser surface passkeys
              // in this field's autofill for the Conditional-UI login above.
              autocomplete="username webauthn"
              autocapitalize="off"
              autocorrect="off"
              spellcheck={false}
              required
              autofocus
              value={username()}
              onInput={(e) => setUsername(e.currentTarget.value)}
            />
          </label>

          <label class="field">
            <span>{m.login_password()}</span>
            <input
              type="password"
              name="_password"
              autocomplete="current-password"
              required
              value={password()}
              onInput={(e) => setPassword(e.currentTarget.value)}
            />
          </label>

          <label class="field-check">
            <input
              type="checkbox"
              name="_remember_me"
              checked={remember()}
              onChange={(e) => setRemember(e.currentTarget.checked)}
            />
            <span>{m.login_remember()}</span>
          </label>

          <button type="submit" id="form-submit" class="primary-button login-submit" disabled={submitting()}>
            {submitting() ? m.login_submitting() : m.login_submit()}
          </button>

          <Show when={passkeysSupported()}>
            <button type="button" class="login-passkey" disabled={submitting()} onClick={() => void signInWithPasskey()}>
              {/* No whitespace text node between the two children: the button is a
                  flex row with gap, so a stray space would become a third flex
                  item and double the gap. */}
              <PasskeyIcon /><span>{m.login_passkey()}</span>
            </button>
          </Show>
        </form>
      </Show>
    </main>
  )
}
