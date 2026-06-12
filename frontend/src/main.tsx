import { render } from 'solid-js/web'

import App from './App'
import { appConfig } from './config'
import { overwriteGetLocale } from './paraglide/runtime.js'
import './styles/app.css'

// Locale comes from the backend user settings, injected via window.APP_CONFIG.
const config = appConfig()
overwriteGetLocale(() => config.locale)

const root = document.getElementById('app')
if (root === null) {
  throw new Error('Mount point #app is missing')
}

render(() => <App />, root)
