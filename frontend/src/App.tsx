import { Navigate, Route, Router } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { type ParentProps } from 'solid-js'

import { SessionExpiredError } from './api/client'
import { ThemeToggle } from './components/ThemeToggle'
import { appConfig } from './config'
import { m } from './paraglide/messages.js'
import Month from './pages/Month'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      retry: (failureCount, error) => !(error instanceof SessionExpiredError) && failureCount < 2,
    },
  },
})

function Layout(props: ParentProps) {
  const config = appConfig()

  return (
    <QueryClientProvider client={queryClient}>
      <a class="skip-link" href="#main-content">
        {m.app_skip_to_content()}
      </a>
      <header class="app-header">
        <p class="app-title">{config.appTitle}</p>
        <nav class="app-nav" aria-label={config.appTitle}>
          <a class="app-nav-link" href={config.legacyUrl}>
            {m.app_classic_ui()}
          </a>
        </nav>
        <ThemeToggle />
      </header>
      <main id="main-content">{props.children}</main>
    </QueryClientProvider>
  )
}

function RedirectToMonth() {
  return <Navigate href="/month" />
}

export default function App() {
  return (
    <Router base="/ui" root={Layout}>
      <Route path="/" component={RedirectToMonth} />
      <Route path="/month" component={Month} />
      <Route path="*rest" component={RedirectToMonth} />
    </Router>
  )
}
