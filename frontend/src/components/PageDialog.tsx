import { Dialog } from '@ark-ui/solid/dialog'
import { createUniqueId, Show, type JSX } from 'solid-js'
import { Portal } from 'solid-js/web'

import { m } from '../paraglide/messages.js'

/**
 * Shared modal scaffolding (Ark Dialog + backdrop + positioner) so the Worklog
 * summary/bulk/delete dialogs and the Admin edit dialog don't each hand-roll the
 * same markup. Pass `title` for a header with a close button; pass only
 * `ariaLabel` for a chrome-less dialog whose content provides its own heading.
 */
export function PageDialog(props: {
  open: boolean
  onClose: () => void
  title?: string
  ariaLabel?: string
  children: JSX.Element
}): JSX.Element {
  const titleId = createUniqueId()

  return (
    <Dialog.Root
      open={props.open}
      onOpenChange={(details) => { if (!details.open) props.onClose() }}
      lazyMount
      unmountOnExit
    >
      <Portal>
        <Dialog.Backdrop class="modal-backdrop" />
        <Dialog.Positioner class="modal-positioner">
          {/* With a visible title, name the dialog by reference (WCAG 1.3.1/4.1.2);
              fall back to aria-label only for a chrome-less dialog. */}
          <Dialog.Content
            class="modal"
            aria-label={props.title === undefined ? props.ariaLabel : undefined}
            aria-labelledby={props.title !== undefined ? titleId : undefined}
          >
            <Show when={props.title !== undefined}>
              <header class="modal-page-header">
                <Dialog.Title id={titleId} class="modal-page-title">{props.title}</Dialog.Title>
                <Dialog.CloseTrigger class="modal-close" aria-label={m.dialog_close()}>×</Dialog.CloseTrigger>
              </header>
            </Show>
            {props.children}
          </Dialog.Content>
        </Dialog.Positioner>
      </Portal>
    </Dialog.Root>
  )
}
