export interface AppConfig {
  locale: 'en' | 'de'
  userId: number
  userName: string
  appTitle: string
  roles: string[]
  showEmptyLine: boolean
  suggestTime: boolean
  showFuture: boolean
  /** Minutes a new entry's end pre-fills past its start (0 disables; default 5). */
  minEntryDuration: number
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
