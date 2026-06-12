import { Navigate, Route, Router } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { onMount, type ParentProps } from 'solid-js'

import { SessionExpiredError } from './api/client'
import { appConfig } from './config'
import { initHeaderDynamics } from './header'
import Month from './pages/Month'

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
// this layout only animates it and hosts the routed content.
function Layout(props: ParentProps) {
  onMount(() => {
    initHeaderDynamics(appConfig())
  })

  return (
    <QueryClientProvider client={queryClient}>
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
