import { For, type JSX } from 'solid-js'

import { m } from '../paraglide/messages.js'
import { WORKLOG_VIEWS, type WorklogView } from '../lib/worklogViewPref'

/**
 * Picks the worklog view. A radiogroup rather than a listbox or a row of
 * buttons: the three views are mutually exclusive and all visible, which is
 * exactly what a radio group models — so a screen reader announces "2 of 3"
 * and arrow keys move the selection, while Tab stays a single stop.
 */
export default function WorklogViewSwitch(props: {
  value: WorklogView
  onChange: (view: WorklogView) => void
}): JSX.Element {
  const label = (view: WorklogView): string => {
    switch (view) {
      case 'grouped':
        return m.worklog_view_grouped()
      case 'flat':
        return m.worklog_view_flat()
      case 'timeline':
        return m.worklog_view_timeline()
    }
  }

  const description = (view: WorklogView): string => {
    switch (view) {
      case 'grouped':
        return m.worklog_view_grouped_hint()
      case 'flat':
        return m.worklog_view_flat_hint()
      case 'timeline':
        return m.worklog_view_timeline_hint()
    }
  }

  // Arrow keys move the selection inside the group (WAI-ARIA radiogroup
  // pattern); Tab enters and leaves it once, landing on the checked option.
  const onKeyDown = (event: KeyboardEvent): void => {
    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1
      : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1
      : 0
    if (step === 0) {
      return
    }

    event.preventDefault()
    const index = WORKLOG_VIEWS.indexOf(props.value)
    const next = WORKLOG_VIEWS[(index + step + WORKLOG_VIEWS.length) % WORKLOG_VIEWS.length]
    if (next === undefined) {
      return
    }

    props.onChange(next)
    // The selection moved, so focus follows it — otherwise the next arrow key
    // would act on a button that is no longer the checked one.
    const el = document.querySelector<HTMLButtonElement>(`[data-worklog-view="${next}"]`)
    el?.focus()
  }

  return (
    <div class="worklog-view-switch" role="radiogroup" aria-label={m.worklog_view_label()} onKeyDown={onKeyDown}>
      <For each={WORKLOG_VIEWS}>
        {(view) => (
          <button
            type="button"
            role="radio"
            class="worklog-view-option"
            data-worklog-view={view}
            aria-checked={props.value === view ? 'true' : 'false'}
            // Only the checked option is a tab stop — the group is one stop.
            tabindex={props.value === view ? 0 : -1}
            title={description(view)}
            onClick={() => props.onChange(view)}
          >
            {label(view)}
          </button>
        )}
      </For>
    </div>
  )
}
