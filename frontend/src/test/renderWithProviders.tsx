import { createMemoryHistory, MemoryRouter, Route } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { render } from '@solidjs/testing-library'
import type { Component, JSX } from 'solid-js'

// A QueryClient tuned for tests: retries off so a rejected fetch surfaces at once
// (no exponential backoff stalling waitFor). Mirrors the per-file config the page
// tests used before this helper existed, so behaviour is unchanged.
export function createTestQueryClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

interface RouteOptions {
  // The path the in-memory history starts on, e.g. '/admin/users' — this is what
  // the component sees as the active URL (params, query string, …).
  initialPath: string
  // The Route's path pattern. Defaults to initialPath when the route is a plain
  // page; pass a pattern (e.g. '/admin/:entity?') when it differs from the URL.
  path?: string
  // The page component mounted at `path`.
  component: Component
}

interface RenderWithProvidersOptions {
  // Reuse a specific client (e.g. to pre-seed the cache via setQueryData before
  // rendering). Omitted → a fresh createTestQueryClient() is used and returned.
  queryClient?: QueryClient
  // Mount the UI under a MemoryRouter + Route. Omitted → no router (the `ui`
  // accessor is rendered directly inside the QueryClientProvider).
  route?: RouteOptions
}

type RenderResult = ReturnType<typeof render>

// Wrap a page render in the providers its tests need: a QueryClientProvider
// always, and a MemoryRouter + Route only when `route` is given. Pass `ui` for a
// routerless page; pass `route` (and omit `ui`) for a routed one — the Route's
// component is what gets mounted. Returns the @solidjs/testing-library result
// plus the queryClient so a test can seed or inspect the cache.
export function renderWithProviders(
  ui: (() => JSX.Element) | undefined,
  options: RenderWithProvidersOptions = {},
): RenderResult & { queryClient: QueryClient } {
  const queryClient = options.queryClient ?? createTestQueryClient()
  const { route } = options

  const result = render(() => (
    <QueryClientProvider client={queryClient}>
      {route !== undefined ? renderRoute(route) : ui?.()}
    </QueryClientProvider>
  ))

  return { ...result, queryClient }
}

function renderRoute(route: RouteOptions): JSX.Element {
  const history = createMemoryHistory()
  history.set({ value: route.initialPath })

  return (
    <MemoryRouter history={history}>
      <Route path={route.path ?? route.initialPath} component={route.component} />
    </MemoryRouter>
  )
}
