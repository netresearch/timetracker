export interface AppConfig {
  locale: 'en' | 'de' | 'es' | 'fr' | 'ru'
  userId: number
  userName: string
  appTitle: string
  roles: string[]
  showEmptyLine: boolean
  suggestTime: boolean
  showFuture: boolean
  /** Minutes a new entry's end pre-fills past its start (0 disables; default 5). */
  minEntryDuration: number
  /** Whether this user opted their worklogs into the Personio attendance export
   *  (ADR-024): the nightly `tt:export-personio-attendances` only touches opted-in
   *  users. */
  personioSyncEnabled: boolean
  /** True when an active Personio config exists admin-side. Without one the
   *  attendance export can't run, so the per-user opt-in is greyed out. */
  personioConfigured: boolean
  /** Whether TOTP two-factor is already enrolled — toggles the Security section's
   *  enable-vs-disable UI. */
  totpEnabled: boolean
  /** Whether this is a local (password) account. LDAP users cannot change a local
   *  password, so the Security section hides that control for them. */
  localAccount: boolean
  /** Org-wide mandatory 2FA (ADR-018): when true, a user without any second factor
   *  is held at the enrolment gate before the app loads. */
  twoFactorRequired: boolean
  /** Whether this user already has a second factor (TOTP or a passkey). */
  hasTwoFactor: boolean
  logoutUrl: string
  /** CSRF token for the 'authenticate' intention — stateless, so it stays valid
   *  across a session that expires while the SPA is open (re-login overlay). */
  csrfToken: string
  /** Where the re-login overlay posts — the unified `_login` route. */
  loginPath: string
}

declare global {
  interface Window {
    APP_CONFIG?: AppConfig
  }
}

// Injected by templates/ui/index.html.twig — present on every server-rendered
// page load, so a missing config is a programming error, not a runtime state.
export function appConfig(): AppConfig {
  const config = window.APP_CONFIG
  if (config === undefined) {
    throw new Error('window.APP_CONFIG is missing — page was not rendered by the ui_spa route')
  }

  return config
}

export function hasRole(role: string): boolean {
  return appConfig().roles.includes(role)
}

/** Whether the org mandates 2FA and this user has not yet enrolled a second
 *  factor — the SPA holds them at the enrolment gate until they do. */
export function needsTwoFactorEnrolment(): boolean {
  const config = appConfig()

  return config.twoFactorRequired && !config.hasTwoFactor
}

/** Project leads and admins may use the billing export. */
export function canBill(): boolean {
  return hasRole('ROLE_PL') || hasRole('ROLE_ADMIN')
}

/** Bulk entry is a user feature: every authenticated user may use it, and the
 *  presets it relies on are a shared, user-readable template source (see
 *  GetPresetsAction / GET /getAllPresets). */
export function canBulkEnter(): boolean {
  return hasRole('ROLE_USER')
}
