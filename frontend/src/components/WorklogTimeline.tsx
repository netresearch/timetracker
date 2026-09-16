import { For, type JSX } from 'solid-js'

import type { TrackingEntry } from '../api/queries'
import { m } from '../paraglide/messages.js'

/** Block height per minute, floored so a short entry stays readable and capped
 *  so one long entry cannot push a whole day off the screen. Scaled so a full
 *  eight-hour day fills roughly the cap: proportions stay comparable while a
 *  normal day still fits in one view. */
const PIXELS_PER_MINUTE = 0.34
const MIN_BLOCK_PX = 30
const MAX_BLOCK_PX = 170

/**
 * Read-only view B: one block per entry, its height proportional to the
 * duration, so a day's shape is visible at a glance. Deliberately NOT an
 * editable grid — proportional rows and inline editing fight each other (an
 * open editor would resize its own row). Activating a block hands the entry
 * back to the caller, which switches to the flat grid and focuses it there.
 */
export default function WorklogTimeline(props: {
  days: string[]
  entriesFor: (day: string) => TrackingEntry[]
  formatDay: (day: string) => string
  formatTotal: (day: string) => string
  onJump: (entryId: number) => void
}): JSX.Element {
  const blockHeight = (minutes: number): string =>
    `${Math.round(Math.min(MAX_BLOCK_PX, Math.max(MIN_BLOCK_PX, minutes * PIXELS_PER_MINUTE)))}px`

  return (
    <div class="worklog-timeline">
      <For each={props.days}>
        {(day) => (
          <section class="worklog-timeline-day" aria-label={props.formatDay(day)}>
            <h3 class="worklog-timeline-dayhead">
              <span>{props.formatDay(day)}</span>
              <span class="worklog-day-total">{props.formatTotal(day)}</span>
            </h3>
            <ul class="worklog-timeline-list">
              <For each={props.entriesFor(day)}>
                {(entry) => (
                  <li>
                    <button
                      type="button"
                      class="worklog-timeline-block"
                      style={{ 'min-height': blockHeight(entry.durationMinutes) }}
                      // The label names the entry AND what activating it does, so
                      // the jump is never a surprise for a screen-reader user.
                      aria-label={m.worklog_timeline_open({
                        ticket: entry.ticket === '' ? m.worklog_timeline_no_ticket() : entry.ticket,
                        duration: entry.duration,
                      })}
                      onClick={() => props.onJump(entry.id)}
                    >
                      <span class="worklog-timeline-time">{entry.start ?? ''}–{entry.end ?? ''}</span>
                      <span class="worklog-timeline-ticket">{entry.ticket}</span>
                      <span class="worklog-timeline-text">{entry.description}</span>
                      <span class="worklog-timeline-duration">{entry.duration}</span>
                    </button>
                  </li>
                )}
              </For>
            </ul>
          </section>
        )}
      </For>
    </div>
  )
}
