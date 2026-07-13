import { Popover } from '@ark-ui/solid/popover'
import type { JSX } from 'solid-js'
import { Portal } from 'solid-js/web'

import { m } from '../paraglide/messages.js'

interface HelpPopoverProps {
  /** Already-localized name of the field/section the help is about. */
  topic: string
  /** The localized explanation shown inside the popover. */
  children: JSX.Element
}

/**
 * An inline "?" trigger opening a short explanation. For one-line facts keep
 * using `<small class="field-hint">`; this is for the "what IS a passkey?"
 * class of help that would bloat the form (spec §7).
 */
export function HelpPopover(props: HelpPopoverProps) {
  return (
    <Popover.Root lazyMount unmountOnExit>
      <Popover.Trigger
        type="button"
        class="help-trigger"
        aria-label={m.help_popover_label({ topic: props.topic })}
      >
        ?
      </Popover.Trigger>
      <Portal>
        <Popover.Positioner>
          <Popover.Content class="help-popover">
            <Popover.Title class="help-popover-title">{props.topic}</Popover.Title>
            <div class="help-popover-body">{props.children}</div>
          </Popover.Content>
        </Popover.Positioner>
      </Portal>
    </Popover.Root>
  )
}
