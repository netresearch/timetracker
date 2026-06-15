import { Dialog } from '@ark-ui/solid/dialog'
import { Navigate, Route, Router, useLocation, useNavigate } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { createEffect, createMemo, createSignal, onMount, Show, type Component, type JSX, type ParentProps } from 'solid-js'
import { Dynamic, Portal } from 'solid-js/web'

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

// Secondary pages that present as a modal dialog over the page you were on.
// Deep-linkable: /ui/settings opens the dialog over the last full page (or Month
// for a direct visit); closing returns to that page.
const MODAL_SEGMENTS = new Set(['settings', 'help', 'extras', 'billing'])

const segmentOf = (pathname: string): string =>
  pathname.replace(/^\/ui\/?/, '').split('/')[0] || 'month'

// useLocation().pathname includes the router base (/ui), but useNavigate()
// prepends the base itself — so navigating to the raw pathname would double it
// (/ui/ui/…) and fall through to the catch-all → Month. Strip the base once.
const toRoutePath = (pathname: string): string => pathname.replace(/^\/ui/, '') || '/month'

/** Wraps a routed modal page in the shared Ark dialog (focus trap, Esc, backdrop). */
function PageDialog(props: { title: string; onClose: () => void; children: JSX.Element }) {
  return (
    <Dialog.Root open onOpenChange={(details) => { if (!details.open) props.onClose() }} lazyMount unmountOnExit>
      <Portal>
        <Dialog.Backdrop class="modal-backdrop" />
        <Dialog.Positioner class="modal-positioner">
          <Dialog.Content class="modal modal-page">
            <header class="modal-page-header">
              <Dialog.Title class="modal-page-title">{props.title}</Dialog.Title>
              <Dialog.CloseTrigger class="modal-close" aria-label={m.dialog_close()}>×</Dialog.CloseTrigger>
            </header>
            <div class="modal-page-body">{props.children}</div>
          </Dialog.Content>
        </Dialog.Positioner>
      </Portal>
    </Dialog.Root>
  )
}

function Layout(props: ParentProps) {
  const location = useLocation()
  const navigate = useNavigate()
  // Theming (apply + toggle) is handled framework-neutrally by the shared
  // header (templates/partials/theme-init.html.twig), so it works on both the
  // ExtJS and SolidJS shells and the SPA needs no theme code of its own.
  let mainRef: HTMLElement | undefined
  let liveRef: HTMLElement | undefined
  let initialRoute = true
  const [showHint, setShowHint] = createSignal(false)
  // The last full-page route — rendered behind an open modal page, and where
  // closing the modal returns to. Defaults to Month for a direct deep-link.
  const [lastFullPath, setLastFullPath] = createSignal('/month')

  const isModal = createMemo(() => MODAL_SEGMENTS.has(segmentOf(location.pathname)))
  const routeTitle = createMemo(() => (PAGE_TITLES[segmentOf(location.pathname)] ?? m.month_title)())
  // #main-content names the BACKGROUND page when a modal is open (that's what
  // the region shows); the modal dialog carries its own title separately.
  const mainHeading = createMemo(() =>
    isModal() ? (PAGE_TITLES[segmentOf(lastFullPath())] ?? m.month_title)() : routeTitle())
  const background = createMemo(() => BG_PAGES[segmentOf(lastFullPath())] ?? Month)

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
    const modal = isModal()
    const title = routeTitle()
    syncNav(location.pathname)
    // WCAG 2.4.2: every route is a "page" and needs its own document title.
    document.title = `${title} – ${appConfig().appTitle}`
    // Remember the last full page so a modal renders it behind and returns to it.
    if (!modal) {
      setLastFullPath(location.pathname)
    }
    if (initialRoute) {
      initialRoute = false

      return
    }
    // A modal page autofocuses its dialog (Ark) and the dialog is labelled, so
    // it announces itself — don't also move focus to #main-content or
    // double-announce via the live region.
    if (modal) {
      return
    }
    // Client-side navigation to a full page: a route swap is silent to screen
    // readers, so push the new page title into a polite live region (WCAG 4.1.3)
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
        <h1 id="page-heading" class="visually-hidden">{mainHeading()}</h1>
        <p ref={(el) => { liveRef = el }} class="visually-hidden" aria-live="polite" aria-atomic="true" />
        {/* On a modal route #main-content shows the page you came from; the
            modal page itself is rendered in the dialog below. */}
        <Show when={isModal()} fallback={props.children}>
          <Dynamic component={background()} />
        </Show>
      </main>
      <Show when={isModal()}>
        <PageDialog title={routeTitle()} onClose={() => navigate(toRoutePath(lastFullPath()))}>
          {props.children}
        </PageDialog>
      </Show>
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

// Full pages that can sit behind an open modal (rendered as the modal's
// backdrop content). Admin keeps its role guard.
const BG_PAGES: Record<string, Component> = {
  month: Month,
  auswertung: Auswertung,
  admin: guarded(Admin, () => hasRole('ROLE_ADMIN')),
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
      <Route path="/admin/:entity?" component={guarded(Admin, () => hasRole('ROLE_ADMIN'))} />
      <Route path="*rest" component={RedirectToMonth} />
    </Router>
  )
}
