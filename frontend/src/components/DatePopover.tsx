import { Popover } from '@ark-ui/solid/popover'
import { Portal } from 'solid-js/web'

import { CalendarIcon } from '../lib/icons'
import { m } from '../paraglide/messages.js'
import { Calendar } from './Calendar'

interface DatePopoverProps {
  /** Controlled open state. */
  open: boolean
  /** Notified when the popover wants to open/close (trigger click, outside, Escape). */
  onOpenChange: (open: boolean) => void
  /** Class for the calendar trigger button. */
  triggerClass: string
  /** ISO yyyy-mm-dd currently selected, or '' for none. */
  value: string
  /** Today's ISO yyyy-mm-dd — the caller owns "now" (frozen clock in tests). */
  todayIso: string
  /** Called with the picked day's ISO yyyy-mm-dd. */
  onSelect: (iso: string) => void
  /** Trigger tab order — omit for the normal tab stop (forms), -1 to keep it out
   *  of the roving-tabindex grid. */
  triggerTabIndex?: number
  /** Disable the trigger (form fields only). */
  disabled?: boolean
  /** Whether opening the calendar moves focus into it. Omit (default true) for
   *  forms; false for the grid so the input keeps focus and never blur-commits. */
  autoFocus?: boolean
  /** Whether the popover closes itself on Escape. Omit (default true) for forms;
   *  false for the grid so the focused input owns Escape (first Escape closes the
   *  calendar, a second cancels the cell) without a focus-restore race. */
  closeOnEscape?: boolean
  /** Whether closing returns focus to the trigger. Omit (default true) for forms;
   *  false for the grid so closing the calendar can't blur the input into a
   *  premature commit. */
  restoreFocus?: boolean
}

/**
 * The body-portalled calendar popover shared by {@link DateField} and the inline
 * grid date editor: a calendar trigger button plus a portalled month picker. Both
 * the trigger and the content preventDefault their mousedown so a click never
 * blurs the input behind them (which would commit + tear down the grid editor).
 * The Positioner carries `data-date-popup` so focus-tracking code can recognise
 * focus inside the calendar as "still editing".
 */
export function DatePopover(props: DatePopoverProps) {
  return (
    <Popover.Root
      open={props.open}
      onOpenChange={(details) => props.onOpenChange(details.open)}
      autoFocus={props.autoFocus}
      closeOnEscape={props.closeOnEscape}
      restoreFocus={props.restoreFocus}
      positioning={{ placement: 'bottom-end', gutter: 4, flip: true, fitViewport: true }}
    >
      <Popover.Trigger
        type="button"
        class={props.triggerClass}
        aria-label={m.date_open_calendar()}
        tabindex={props.triggerTabIndex}
        disabled={props.disabled}
        // Keep focus on the input so opening the calendar never blur-commits.
        onMouseDown={(event) => event.preventDefault()}
      >
        <CalendarIcon />
      </Popover.Trigger>
      <Portal>
        <Popover.Positioner class="date-popover-positioner" data-date-popup>
          <Popover.Content
            class="date-popover"
            // A click inside the calendar must not blur-commit/steal focus.
            onMouseDown={(event) => event.preventDefault()}
          >
            <Calendar value={props.value} todayIso={props.todayIso} onSelect={props.onSelect} />
          </Popover.Content>
        </Popover.Positioner>
      </Portal>
    </Popover.Root>
  )
}
