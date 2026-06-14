import { render } from 'solid-js/web'

import { LoginForm } from './components/LoginForm'
import { loginConfig } from './loginConfig'
import { overwriteGetLocale } from './paraglide/runtime.js'
import './styles/app.css'

// Locale is injected by templates/login.html.twig via window.LOGIN_CONFIG.
const config = loginConfig()
overwriteGetLocale(() => config.locale)

const root = document.getElementById('login')
if (root === null) {
  throw new Error('Mount point #login is missing')
}

// Replace the server-rendered no-JS fallback form with the interactive one.
root.replaceChildren()
render(() => <LoginForm config={config} />, root)
