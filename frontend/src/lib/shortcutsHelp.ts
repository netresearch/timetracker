import { createSignal } from 'solid-js'

// Module-singleton open state for the ?-triggered keyboard-shortcuts dialog,
// mirroring commandPalette.ts. header.ts flips it on `?`; ShortcutsDialog reads
// it; the command palette offers it as an explicit entry too.
export const [shortcutsHelpOpen, setShortcutsHelpOpen] = createSignal(false)
