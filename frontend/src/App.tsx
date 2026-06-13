import { Navigate, Route, Router, useLocation } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { createEffect, createSignal, onMount, Show, type Component, type ParentProps } from 'solid-js'
import { Portal } from 'solid-js/web'

import { SessionExpiredError } from './api/client'
import { ThemeToggle } from './components/ThemeToggle'
import { appConfig, canBill, canBulkEnter, hasRole } from './config'
import { initHeaderDynamics } from './header'
import { syncNav } from './nav'
import Admin from './pages/Admin'
import Auswertung from './pages/Auswertung'
import Billing from './pages/Billing'
import Extras from './pages/Extras'
import Help from './pages/Help'
import Month from './pages/Month'
import Settings from './pages/Settings'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      retry: (failureCount, error) => !(error instanceof SessionExpiredError) && failureCount < 2,
    },
  },
})

// The page chrome (header + main nav) is server-rendered by
// templates/partials/header.html.twig, shared with the ExtJS shell;
// this layout animates it, syncs the active nav item, and hosts the route.
function Layout(props: ParentProps) {
  const location = useLocation()
  // The theme switch lives in the server-rendered header (shared with the
  // ExtJS shell); portal the SolidJS control into its slot so it sits in the
  // header chrome rather than each page's body. Resolve the slot in onMount so
  // we read the DOM after it's ready, not during component setup.
  const [themeMount, setThemeMount] = createSignal<HTMLElement | null>(null)

  onMount(() => {
    initHeaderDynamics(appConfig())
    setThemeMount(document.getElementById('theme-toggle-mount'))
  })

  createEffect(() => {
    syncNav(location.pathname)
  })

  return (
    <QueryClientProvider client={queryClient}>
      <Show when={themeMount()}>{(mount) => <Portal mount={mount()}><ThemeToggle /></Portal>}</Show>
      <main id="main-content">{props.children}</main>
    </QueryClientProvider>
  )
}

function RedirectToMonth() {
  return <Navigate href="/month" />
}

// Route guards mirror the role gates in the shared nav: a direct URL to a
// role-restricted page falls back to the month view instead of 403-ing on
// its data endpoints.
function guarded(component: Component, allowed: () => boolean): Component {
  return () => (allowed() ? component({}) : <RedirectToMonth />)
}

export default function App() {
  return (
    <Router base="/ui" root={Layout}>
      <Route path="/" component={RedirectToMonth} />
      <Route path="/month" component={Month} />
      <Route path="/auswertung" component={Auswertung} />
      <Route path="/settings" component={Settings} />
      <Route path="/help" component={Help} />
      <Route path="/extras" component={guarded(Extras, canBulkEnter)} />
      <Route path="/billing" component={guarded(Billing, canBill)} />
      <Route path="/admin" component={guarded(Admin, () => hasRole('ROLE_ADMIN'))} />
      <Route path="*rest" component={RedirectToMonth} />
    </Router>
  )
}
