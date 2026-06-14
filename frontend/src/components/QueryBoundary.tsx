import { Match, Switch, type JSX } from 'solid-js'

import { m } from '../paraglide/messages.js'

/** Minimal shape of a TanStack query result this boundary needs. */
interface QueryLike {
  isError: boolean
  isLoading: boolean
}

/**
 * Renders one of three states for a query — error, loading, or data — making
 * the intent explicit instead of nesting inverted `<Show when={!isError}>` /
 * `<Show when={!isLoading}>` guards. Pass `error`/`loading` to override the
 * default messages (e.g. to keep a chart's card + heading visible).
 */
export function QueryBoundary(props: {
  query: QueryLike
  error?: JSX.Element
  loading?: JSX.Element
  children: JSX.Element
}) {
  return (
    <Switch>
      <Match when={props.query.isError}>{props.error ?? <p role="alert">{m.app_load_error()}</p>}</Match>
      <Match when={props.query.isLoading}>{props.loading ?? <p class="effort-empty">{m.app_loading()}</p>}</Match>
      <Match when={true}>{props.children}</Match>
    </Switch>
  )
}
