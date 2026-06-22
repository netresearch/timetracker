import { Dialog } from '@ark-ui/solid/dialog'
import { createSignal, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import { appConfig } from '../config'
import { m } from '../paraglide/messages.js'

/**
 * Shown when the backend session is lost (see lib/session). A non-dismissible
 * modal over the dimmed, inert page: the user re-authenticates in place and the
 * SPA resumes exactly where it was — nothing navigates or unmounts, so the
 * in-progress work (worklog drafts, new rows, the bulk form) survives (issue #408).
 *
 * Reuses the LoginForm submit contract (XHR POST to `_login` → JSON {ok, redirect};
 * the firewall attaches the fresh session cookie). On success it calls onSuccess
 * (dismiss + refetch) instead of navigating. The 'authenticate' CSRF token is
 * stateless, so the page-load token in APP_CONFIG is still valid after expiry.
 */
export function SessionExpiredOverlay(props: { onSuccess: () => void }) {
  const cfg = appConfig()
  const [username, setUsername] = createSignal(cfg.userName)
  const [password, setPassword] = createSignal('')
  const [error, setError] = createSignal<string | null>(null)
  const [submitting, setSubmitting] = createSignal(false)
  let passwordEl: HTMLInputElement | undefined

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
      // Same shape LoginForm uses — X-Requested-With is what makes the
      // authenticator answer with JSON instead of a 302 (it keys on isXmlHttpRequest).
      const response = await fetch(cfg.loginPath, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: new URLSearchParams({
          _username: username(),
          _password: password(),
          _csrf_token: cfg.csrfToken,
          _remember_me: 'on',
        }),
      })
      const data = (await response.json().catch(() => null)) as { ok?: boolean } | null
      if (response.ok && data?.ok === true) {
        props.onSuccess()

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

            <form class="session-form" onSubmit={submit} novalidate>
              <Show when={error()}>
                <p class="form-status is-error" role="alert">{error()}</p>
              </Show>

              <label class="field">
                <span>{m.login_username()}</span>
                <input
                  type="text"
                  name="_username"
                  autocomplete="username"
                  autocapitalize="off"
                  autocorrect="off"
                  spellcheck={false}
                  value={username()}
                  onInput={(e) => setUsername(e.currentTarget.value)}
                />
              </label>

              <label class="field">
                <span>{m.login_password()}</span>
                <input
                  ref={(el) => { passwordEl = el }}
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
            </form>

            {/* Escape hatch: full-page login (reload) for a persistent failure or
                a different user — the safe path when an in-place resume can't apply. */}
            <a class="session-fallback" href={cfg.loginPath}>{m.session_expired_fallback()}</a>
          </Dialog.Content>
        </Dialog.Positioner>
      </Portal>
    </Dialog.Root>
  )
}
