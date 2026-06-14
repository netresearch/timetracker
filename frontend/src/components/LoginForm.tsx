import { createSignal, Show } from 'solid-js'

import type { LoginConfig } from '../loginConfig'
import { m } from '../paraglide/messages.js'

/**
 * SolidJS login form. Renders a real <form> that posts to the Symfony `_login`
 * route, so it works without JS (progressive enhancement); when JS is active it
 * submits via fetch (X-Requested-With) and shows inline errors without a reload.
 * The field names (_username, _password, _csrf_token, _remember_me) and the
 * #form-submit id match what the firewall and the e2e suite expect.
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
      const response = await fetch(props.config.loginPath, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: new URLSearchParams({
          _username: username(),
          _password: password(),
          _csrf_token: props.config.csrfToken,
          ...(remember() ? { _remember_me: 'on' } : {}),
        }),
      })
      const data = (await response.json().catch(() => null)) as { ok?: boolean; redirect?: string } | null
      if (response.ok && data?.ok === true) {
        window.location.assign(data.redirect ?? '/')

        return
      }
      setError(m.login_error())
    } catch {
      setError(m.login_error())
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main class="login-page">
      <form class="login-card" method="post" action={props.config.loginPath} onSubmit={submit} novalidate>
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
            autocomplete="username"
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
      </form>
    </main>
  )
}
