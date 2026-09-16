import { For, type JSX } from 'solid-js'

/**
 * A segmented control over a small, fixed set of mutually exclusive options,
 * built as a WAI-ARIA radiogroup: a screen reader announces "2 of 3", arrow keys
 * move the selection (wrapping at both ends), and Tab enters and leaves the group
 * once, landing on the checked option.
 *
 * Shared by the worklog's view and order switches. They looked alike but were two
 * implementations, and only one of them had the arrow-key handler — the order
 * could not be changed without a pointer at all.
 */
export default function SegmentedSwitch<T extends string>(props: {
  options: readonly T[]
  value: T
  onChange: (value: T) => void
  /** Names the group itself (the radiogroup's accessible name). */
  label: string
  optionLabel: (option: T) => string
  /** The option's tooltip; its own label when not given. */
  optionTitle?: (option: T) => string
  icon: (option: T) => JSX.Element
}): JSX.Element {
  let group: HTMLDivElement | undefined

  const stepFor = (key: string): number => {
    if (key === 'ArrowRight' || key === 'ArrowDown') {
      return 1
    }

    return key === 'ArrowLeft' || key === 'ArrowUp' ? -1 : 0
  }

  const onKeyDown = (event: KeyboardEvent): void => {
    const step = stepFor(event.key)
    if (step === 0) {
      return
    }

    event.preventDefault()
    const index = props.options.indexOf(props.value)
    const next = props.options[(index + step + props.options.length) % props.options.length]
    if (next === undefined) {
      return
    }

    props.onChange(next)
    // The selection moved, so focus follows it — otherwise the next arrow key
    // would act on a button that is no longer the checked one.
    group?.querySelector<HTMLButtonElement>(`[data-segment-value="${next}"]`)?.focus()
  }

  return (
    <div
      class="worklog-view-switch"
      role="radiogroup"
      aria-label={props.label}
      // The handler sits on the GROUP, where the keydown from the focused radio
      // bubbles to. SonarCloud's S6852 asks for a focusable radiogroup instead;
      // that is the wrong shape here — WAI-ARIA's radiogroup pattern puts the tab
      // stop on the checked radio, and making the container focusable would add a
      // second one.
      onKeyDown={onKeyDown}
      ref={(el) => { group = el }}
    >
      <For each={props.options}>
        {(option) => (
          <button
            type="button"
            role="radio"
            class="worklog-view-option"
            data-segment-value={option}
            aria-checked={props.value === option ? 'true' : 'false'}
            // Only the checked option is a tab stop — the group is one stop.
            tabindex={props.value === option ? 0 : -1}
            title={props.optionTitle?.(option) ?? props.optionLabel(option)}
            onClick={() => props.onChange(option)}
          >
            {/* Each option carries an icon as well as its label: the sidebar
                collapses to a 3.5rem rail where only icons fit. The label stays in
                the markup and is hidden by CSS there, so assistive technology
                keeps reading the words. */}
            {props.icon(option)}
            <span class="worklog-view-text">{props.optionLabel(option)}</span>
          </button>
        )}
      </For>
    </div>
  )
}
