import { Dialog } from '@ark-ui/solid/dialog'
import { A } from '@solidjs/router'
import { Portal } from 'solid-js/web'

import { GLOBAL_SHORTCUTS, GRID_SHORTCUTS, TRACKING_SHORTCUTS } from '../lib/shortcuts'
import { setShortcutsHelpOpen, shortcutsHelpOpen } from '../lib/shortcutsHelp'
import { m } from '../paraglide/messages.js'
import { ShortcutTable } from './ShortcutTable'

/**
 * The `?`-triggered keyboard-shortcuts cheat-sheet: a quick, dismissible overlay
 * (Escape / backdrop / ✕) so the reference is one keypress away without leaving
 * the current page. The full /help page carries the same tables plus usage/links.
 */
export function ShortcutsDialog() {
  const close = (): void => {
    setShortcutsHelpOpen(false)
  }

  return (
    <Dialog.Root open={shortcutsHelpOpen()} onOpenChange={(details) => { if (!details.open) close() }} lazyMount unmountOnExit>
      <Portal>
        <Dialog.Backdrop class="modal-backdrop" />
        <Dialog.Positioner class="modal-positioner shortcuts-positioner">
          <Dialog.Content class="shortcuts-dialog" aria-label={m.help_shortcuts()}>
            <header class="shortcuts-dialog-head">
              <h2 class="shortcuts-dialog-title">{m.help_shortcuts()}</h2>
              <button type="button" class="shortcuts-dialog-close" aria-label={m.kbd_hint_dismiss()} onClick={close}>✕</button>
            </header>
            <p class="help-intro">{m.help_shortcuts_intro()}</p>
            <div class="shortcut-tables">
              <ShortcutTable caption={m.help_shortcuts_global()} rows={GLOBAL_SHORTCUTS} />
              <ShortcutTable caption={m.help_shortcuts_grid()} rows={GRID_SHORTCUTS} />
              <ShortcutTable caption={m.help_shortcuts_tracking()} rows={TRACKING_SHORTCUTS} />
            </div>
            <footer class="shortcuts-dialog-foot">
              <A href="/help" class="shortcuts-full-help" onClick={close}>{m.help_full()} →</A>
            </footer>
          </Dialog.Content>
        </Dialog.Positioner>
      </Portal>
    </Dialog.Root>
  )
}
