export interface LoginConfig {
  locale: 'en' | 'de'
  appTitle: string
  logoUrl: string
  /** CSRF token for the 'authenticate' intention (form_login, enable_csrf). */
  csrfToken: string
  /** Where the form posts — the unified `_login` route. */
  loginPath: string
  /** Pre-filled on a server-rendered re-display after a failed no-JS submit. */
  lastUsername: string
  /** Server-side auth error message for the no-JS fallback path (else null). */
  error: string | null
}

declare global {
  interface Window {
    LOGIN_CONFIG?: LoginConfig
  }
}

// Injected by templates/login.html.twig on every render of the login page.
export function loginConfig(): LoginConfig {
  const config = window.LOGIN_CONFIG
  if (config === undefined) {
    throw new Error('window.LOGIN_CONFIG is missing — page was not rendered by the _login route')
  }

  return config
}
