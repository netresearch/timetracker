import { createSignal } from 'solid-js'

// A reactive hand-off so the left-sidebar admin menu's per-entity "+" can open the
// add form on a page it doesn't own. The "+" records the target entity and
// navigates to /ui/admin/<key>; AdminCrudShell observes this reactively (not just
// on mount) and opens its create form when the value matches its entity — so it
// also works when you are already on that entity's page and the shell does not
// remount. Null = no pending request.
const [pendingAdd, setPendingAdd] = createSignal<string | null>(null)

export { pendingAdd }

/** Request that the given entity's add form open. */
export function requestAdd(entityKey: string): void {
  setPendingAdd(entityKey)
}

/** Clear the pending request (called by the shell once it has handled it). */
export function clearPendingAdd(): void {
  setPendingAdd(null)
}
