import { Show, type JSX } from 'solid-js'

import SegmentedSwitch from './SegmentedSwitch'
import { m } from '../paraglide/messages.js'
import { WORKLOG_VIEWS, type WorklogView } from '../lib/worklogViewPref'

/**
 * Picks the worklog view. A radiogroup rather than a listbox or a row of
 * buttons: the views are mutually exclusive and all visible, which is exactly
 * what a radio group models — so a screen reader announces "2 of 2" and arrow
 * keys move the selection, while Tab stays a single stop. The behaviour lives in
 * SegmentedSwitch, shared with the order switch.
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
    }
  }

  const description = (view: WorklogView): string => {
    switch (view) {
      case 'grouped':
        return m.worklog_view_grouped_hint()
      case 'flat':
        return m.worklog_view_flat_hint()
    }
  }

  const icon = (view: WorklogView): JSX.Element => (
    <svg class="worklog-view-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <Show
        when={view === 'grouped'}
        fallback={<path d="M4 7h16M4 12h16M4 17h16" />}
      >
        {/* Grouped: a heading with its rows beneath it, twice. */}
        <><path d="M4 5h16" /><path d="M7 9h13M7 12h13" /><path d="M4 16h16" /><path d="M7 20h13" /></>
      </Show>
    </svg>
  )

  return (
    <SegmentedSwitch
      options={WORKLOG_VIEWS}
      value={props.value}
      onChange={props.onChange}
      label={m.worklog_view_label()}
      optionLabel={label}
      optionTitle={(view) => `${label(view)} — ${description(view)}`}
      icon={icon}
    />
  )
}
