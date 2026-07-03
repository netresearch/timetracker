import { createSignal, Show, For, type JSX } from 'solid-js'

import { apiErrorMessage, postJson } from '../api/client'
import { appConfig } from '../config'
import { m } from '../paraglide/messages.js'

/** Server response from POST /settings/2fa/totp/start. */
interface EnrollmentStart {
  provisioningUri: string
  secret: string
}

/** Server response from POST /settings/2fa/totp/confirm. */
interface EnrollmentConfirm {
  enabled: boolean
  backupCodes: string[]
}

type Feedback = { kind: 'ok' | 'error'; message: string } | null

/**
 * Settings → Security. Two independent, per-account server operations that do NOT
 * belong to the batched /settings/save form: a self-service password change (local
 * accounts only) and TOTP two-factor enrolment / removal.
 *
 * State starts from the server-rendered APP_CONFIG snapshot (`totpEnabled`,
 * `localAccount`) and is updated locally after each operation so the UI reflects the
 * new state without a full page reload.
 */
export function SecuritySection(): JSX.Element {
  const config = appConfig()

  return (
    <div class="stack-form">
      <fieldset class="settings-group">
        <legend>{m.settings_section_security()}</legend>
        <p class="settings-section-hint">{m.settings_section_security_hint()}</p>

        <TwoFactorControls initiallyEnabled={config.totpEnabled} />
        <Show when={config.localAccount}>
          <PasswordChange />
        </Show>
      </fieldset>
    </div>
  )
}

/** Change-your-own-password form → POST /settings/password (local accounts only). */
function PasswordChange(): JSX.Element {
  const [current, setCurrent] = createSignal('')
  const [next, setNext] = createSignal('')
  const [confirm, setConfirm] = createSignal('')
  const [busy, setBusy] = createSignal(false)
  const [feedback, setFeedback] = createSignal<Feedback>(null)

  async function submit(event: Event): Promise<void> {
    event.preventDefault()
    if (next() !== confirm()) {
      setFeedback({ kind: 'error', message: m.settings_password_mismatch() })

      return
    }

    setBusy(true)
    setFeedback(null)
    try {
      await postJson('/settings/password', { currentPassword: current(), newPassword: next() })
      setFeedback({ kind: 'ok', message: m.settings_password_changed() })
      setCurrent('')
      setNext('')
      setConfirm('')
    } catch (error) {
      setFeedback({ kind: 'error', message: apiErrorMessage(error, m.settings_password_error()) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form class="security-block" onSubmit={submit}>
      <h3 class="security-heading">{m.settings_password_heading()}</h3>
      <label class="field">
        <span>{m.settings_password_current()}</span>
        <input id="security-current-password" name="current-password" type="password" autocomplete="current-password" required value={current()} onInput={(e) => setCurrent(e.currentTarget.value)} />
      </label>
      <label class="field">
        <span>{m.settings_password_new()}</span>
        <input id="security-new-password" name="new-password" type="password" autocomplete="new-password" required minLength={8} value={next()} onInput={(e) => setNext(e.currentTarget.value)} />
      </label>
      <label class="field">
        <span>{m.settings_password_confirm()}</span>
        <input id="security-confirm-password" name="confirm-password" type="password" autocomplete="new-password" required minLength={8} value={confirm()} onInput={(e) => setConfirm(e.currentTarget.value)} />
      </label>
      <div class="form-actions">
        <button type="submit" class="primary-button" disabled={busy()}>
          {busy() ? m.app_saving() : m.settings_password_submit()}
        </button>
        <Show when={feedback()}>
          {(fb) => (
            <span role={fb().kind === 'error' ? 'alert' : 'status'} class={`form-status is-${fb().kind}`}>
              {fb().message}
            </span>
          )}
        </Show>
      </div>
    </form>
  )
}

/** Enable / disable TOTP two-factor. */
function TwoFactorControls(props: { initiallyEnabled: boolean }): JSX.Element {
  const [enabled, setEnabled] = createSignal(props.initiallyEnabled)
  const [enrollment, setEnrollment] = createSignal<EnrollmentStart | null>(null)
  const [code, setCode] = createSignal('')
  const [backupCodes, setBackupCodes] = createSignal<string[] | null>(null)
  const [busy, setBusy] = createSignal(false)
  const [error, setError] = createSignal('')

  async function start(): Promise<void> {
    setBusy(true)
    setError('')
    try {
      setEnrollment(await postJson<EnrollmentStart>('/settings/2fa/totp/start', {}))
    } catch (caught) {
      setError(apiErrorMessage(caught, m.settings_2fa_error()))
    } finally {
      setBusy(false)
    }
  }

  async function confirm(event: Event): Promise<void> {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const result = await postJson<EnrollmentConfirm>('/settings/2fa/totp/confirm', { code: code() })
      setBackupCodes(result.backupCodes)
      setEnrollment(null)
      setCode('')
      setEnabled(true)
    } catch (caught) {
      setError(apiErrorMessage(caught, m.settings_2fa_error()))
    } finally {
      setBusy(false)
    }
  }

  async function disable(): Promise<void> {
    // Turning off 2FA weakens the account — confirm before the single click acts.
    if (!globalThis.confirm(m.settings_2fa_disable_confirm())) {
      return
    }
    setBusy(true)
    setError('')
    try {
      await postJson('/settings/2fa/disable', {})
      setEnabled(false)
      setBackupCodes(null)
    } catch (caught) {
      setError(apiErrorMessage(caught, m.settings_2fa_error()))
    } finally {
      setBusy(false)
    }
  }

  /** Abandon an in-progress enrolment, clearing the typed code and any error so a
   *  later attempt starts clean (no stale code pre-filled). */
  function cancelEnrolment(): void {
    setEnrollment(null)
    setCode('')
    setError('')
  }

  return (
    <div class="security-block">
      <h3 class="security-heading">{m.settings_2fa_heading()}</h3>

      {/* Backup codes are shown exactly once, right after a successful enrolment. */}
      <Show when={backupCodes()}>
        {(codes) => (
          <div class="security-backup" role="status">
            <p class="security-backup-heading">{m.settings_2fa_backup_heading()}</p>
            <p class="field-hint">{m.settings_2fa_backup_hint()}</p>
            <ul class="security-backup-list">
              <For each={codes()}>{(entry) => <li>{entry}</li>}</For>
            </ul>
            <button type="button" class="primary-button" onClick={() => setBackupCodes(null)}>
              {m.settings_2fa_backup_done()}
            </button>
          </div>
        )}
      </Show>

      <Show when={!backupCodes()}>
        <Show
          when={enabled()}
          fallback={
            <Show
              when={enrollment()}
              fallback={
                <div class="security-row">
                  <p class="field-hint">{m.settings_2fa_off_hint()}</p>
                  <button type="button" class="primary-button" disabled={busy()} onClick={() => void start()}>
                    {m.settings_2fa_enable()}
                  </button>
                </div>
              }
            >
              {(active) => (
                <form class="security-enroll" onSubmit={confirm}>
                  <p class="field-hint">{m.settings_2fa_scan_hint()}</p>
                  <label class="field">
                    <span>{m.settings_2fa_secret_label()}</span>
                    <input class="security-secret" type="text" readOnly value={active().secret} onFocus={(e) => e.currentTarget.select()} />
                  </label>
                  <p class="field-hint">
                    <a href={active().provisioningUri}>{m.settings_2fa_uri_link()}</a>
                  </p>
                  <label class="field">
                    <span>{m.settings_2fa_code()}</span>
                    <input type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" required value={code()} onInput={(e) => setCode(e.currentTarget.value)} />
                  </label>
                  <div class="form-actions">
                    <button type="submit" class="primary-button" disabled={busy()}>
                      {busy() ? m.app_saving() : m.settings_2fa_confirm()}
                    </button>
                    <button type="button" class="ghost-button" disabled={busy()} onClick={cancelEnrolment}>
                      {m.app_cancel()}
                    </button>
                  </div>
                </form>
              )}
            </Show>
          }
        >
          <div class="security-row">
            <p role="status" class="security-on">{m.settings_2fa_on()}</p>
            <button type="button" class="ghost-button" disabled={busy()} onClick={() => void disable()}>
              {m.settings_2fa_disable()}
            </button>
          </div>
        </Show>
      </Show>

      <Show when={error()}>
        <span role="alert" class="form-status is-error">{error()}</span>
      </Show>
    </div>
  )
}
