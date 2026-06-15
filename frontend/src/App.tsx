import { Navigate, Route, Router, useLocation } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { createEffect, createMemo, createSignal, onMount, Show, type Component, type ParentProps } from 'solid-js'

import { SessionExpiredError } from './api/client'
import { appConfig, canBill, canBulkEnter, hasRole } from './config'
import { initHeaderDynamics } from './header'
import { syncNav } from './nav'
import { m } from './paraglide/messages.js'
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
// Route segment → page title. Drives the single <h1>, the #main-content
// accessible name, the screen-reader route announcement, and document.title —
// one source so they never drift.
const PAGE_TITLES: Record<string, () => string> = {
  month: m.month_title,
  auswertung: m.auswertung_title,
  admin: m.admin_title,
  extras: m.extras_title,
  billing: m.billing_title,
  settings: m.settings_title,
  help: m.help_title,
}

// Shown once ever: the keyboard model is otherwise invisible, so on the first
// load we surface a dismissible hint pointing at the "?" shortcut overview.
const HINT_SEEN_KEY = 'tt-kbd-hint-seen'

function Layout(props: ParentProps) {
  const location = useLocation()
  // Theming (apply + toggle) is handled framework-neutrally by the shared
  // header (templates/partials/theme-init.html.twig), so it works on both the
  // ExtJS and SolidJS shells and the SPA needs no theme code of its own.
  let mainRef: HTMLElement | undefined
  let liveRef: HTMLElement | undefined
  let initialRoute = true
  const [showHint, setShowHint] = createSignal(false)

  const routeTitle = createMemo(() => {
    const segment = location.pathname.replace(/^\/ui\/?/, '').split('/')[0] || 'month'

    return (PAGE_TITLES[segment] ?? m.month_title)()
  })

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
    // First-run discoverability: reveal the keyboard-shortcut hint once, then
    // never again. Guarded so a blocked localStorage (private mode) just skips it.
    try {
      if (localStorage.getItem(HINT_SEEN_KEY) === null) {
        localStorage.setItem(HINT_SEEN_KEY, '1')
        setShowHint(true)
        window.setTimeout(() => setShowHint(false), 9000)
      }
    } catch {
      // localStorage unavailable — skip the hint.
    }
  })

  createEffect(() => {
    const title = routeTitle()
    syncNav(location.pathname)
    // WCAG 2.4.2: every route is a "page" and needs its own document title.
    document.title = `${title} – ${appConfig().appTitle}`
    if (initialRoute) {
      initialRoute = false

      return
    }
    // Client-side navigation: a route swap is silent to screen readers, so push
    // the new page title into a polite live region (WCAG 4.1.3 status message),
    // and move focus to the page region (WCAG 2.4.3) — from there ArrowUp
    // re-enters the menubar (handled in header.ts), so the nav stays reachable.
    if (liveRef !== undefined) {
      liveRef.textContent = title
    }
    mainRef?.focus({ preventScroll: true })
  })

  return (
    <QueryClientProvider client={queryClient}>
      {/* Single per-route <h1> (visually hidden — no visual change) gives a
          valid heading outline and names the focus region (aria-labelledby).
          Pages render their own sub-sections as <h2>. */}
      <main id="main-content" ref={(el) => { mainRef = el }} tabindex="-1" aria-labelledby="page-heading">
        <h1 id="page-heading" class="visually-hidden">{routeTitle()}</h1>
        <p ref={(el) => { liveRef = el }} class="visually-hidden" aria-live="polite" aria-atomic="true" />
        {props.children}
      </main>
      <Show when={showHint()}>
        <div class="kbd-hint" role="status">
          <span>{m.kbd_hint()}</span>
          <button type="button" class="kbd-hint-close" aria-label={m.kbd_hint_dismiss()} onClick={() => setShowHint(false)}>×</button>
        </div>
      </Show>
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
