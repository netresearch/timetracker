export interface AppConfig {
  locale: 'en' | 'de'
  userId: number
  userName: string
  appTitle: string
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
