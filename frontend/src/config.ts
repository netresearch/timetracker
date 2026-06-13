export interface AppConfig {
  locale: 'en' | 'de'
  userId: number
  userName: string
  appTitle: string
  roles: string[]
  showEmptyLine: boolean
  suggestTime: boolean
  showFuture: boolean
  logoutUrl: string
  legacyUrl: string
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

/** Bulk entry depends on presets, which are ROLE_ADMIN-only. */
export function canBulkEnter(): boolean {
  return hasRole('ROLE_ADMIN')
}
