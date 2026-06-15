import { Navigate, Route, Router, useLocation } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { createEffect, onMount, type Component, type ParentProps } from 'solid-js'

import { SessionExpiredError } from './api/client'
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
  // Theming (apply + toggle) is handled framework-neutrally by the shared
  // header (templates/partials/theme-init.html.twig), so it works on both the
  // ExtJS and SolidJS shells and the SPA needs no theme code of its own.
  let mainRef: HTMLElement | undefined
  let initialRoute = true

  onMount(() => {
    initHeaderDynamics(appConfig())
    // Initial load / F5: land focus on the page region (only if the browser
    // hasn't restored focus elsewhere) so keyboard navigation is available
    // immediately — without it, focus sits on <body> and the arrow-key chain
    // has no entry point until the user clicks or tabs. preventScroll keeps the
    // viewport put; #main-content is a tabindex=-1 region (no visible ring).
    if (document.activeElement === null || document.activeElement === document.body) {
      mainRef?.focus({ preventScroll: true })
    }
  })

  createEffect(() => {
    syncNav(location.pathname)
    // On client-side navigation (not the first paint) move focus to the page
    // region so keyboard/screen-reader users land on the new content instead
    // of staying on the just-clicked nav link (WCAG 2.4.3). From there ArrowUp
    // re-enters the menubar (handled in header.ts), so the nav stays reachable.
    if (initialRoute) {
      initialRoute = false

      return
    }
    mainRef?.focus({ preventScroll: true })
  })

  return (
    <QueryClientProvider client={queryClient}>
      <main id="main-content" ref={(el) => { mainRef = el }} tabindex="-1">{props.children}</main>
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
