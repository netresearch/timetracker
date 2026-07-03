import { render } from 'solid-js/web'

import App from './App'
import { TwoFactorGate } from './components/TwoFactorGate'
import { appConfig, needsTwoFactorEnrolment } from './config'
import { overwriteGetLocale } from './paraglide/runtime.js'
import './styles/app.css'

// Locale comes from the backend user settings, injected via window.APP_CONFIG.
const config = appConfig()
overwriteGetLocale(() => config.locale)

const root = document.getElementById('app')
if (root === null) {
  throw new Error('Mount point #app is missing')
}

// Org-wide mandatory 2FA (ADR-018): a user without a second factor gets the
// enrolment gate instead of the app. The backend enforces the same rule on its
// APIs — this is the matching UX, not the security boundary.
render(() => (needsTwoFactorEnrolment() ? <TwoFactorGate /> : <App />), root)
