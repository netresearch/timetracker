import { createSignal } from 'solid-js'

/**
 * A runnable command surfaced in the Ctrl/⌘+K command palette. Global commands
 * (navigation, logout, theme/density) are built by the palette itself; pages
 * contribute their context actions via registerCommands().
 */
export interface Command {
  id: string
  /** Display label (reactive — reads paraglide messages). */
  label: () => string
  /** Group heading the command sorts under (Navigation / Worklog / …). */
  group: () => string
  run: () => void
  /** Extra search terms beyond the label. */
  keywords?: () => string
  /** A direct-shortcut hint shown beside the command (e.g. "Alt+P"). */
  shortcut?: string
}

const [registered, setRegistered] = createSignal<Command[]>([])

/** The commands pages have contributed for the current view. */
export { registered as registeredCommands }

/**
 * Register context commands for the lifetime of a page. Returns a disposer that
 * removes exactly these commands — call it from onCleanup so a view's actions
 * leave the palette when you navigate away.
 */
export function registerCommands(commands: Command[]): () => void {
  setRegistered((prev) => [...prev, ...commands])

  return () => setRegistered((prev) => prev.filter((command) => !commands.includes(command)))
}

const [paletteOpen, setPaletteOpen] = createSignal(false)

export { paletteOpen, setPaletteOpen }
