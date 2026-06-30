import { createSignal } from 'solid-js'

// A one-shot hand-off so the left-sidebar admin menu's per-entity "+" can open the
// add form on a page it doesn't own. The "+" records the target entity and
// navigates to /ui/admin/<key>; AdminCrudShell (which remounts per entity) reads
// and clears this on mount and opens its create form. Null = no pending request.
const [pending, setPending] = createSignal<string | null>(null)

/** Request that the given entity's add form open once its shell mounts. */
export function requestAdd(entityKey: string): void {
  setPending(entityKey)
}

/** Read and clear the pending entity (returns null if none / mismatch caller). */
export function takePendingAdd(): string | null {
  const value = pending()
  if (value !== null) {
    setPending(null)
  }

  return value
}
