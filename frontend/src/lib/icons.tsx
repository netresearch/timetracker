import type { JSX } from 'solid-js'

// 24×24 line icons (stroke = currentColor) shared by the grid action buttons,
// so the SVG paths live in one place instead of being inlined per button.
function Icon(props: { children: JSX.Element }): JSX.Element {
  return (
    <svg class="action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      {props.children}
    </svg>
  )
}

export function EditIcon(): JSX.Element {
  return <Icon><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></Icon>
}

export function TrashIcon(): JSX.Element {
  return (
    <Icon>
      <path d="M3 6h18" />
      <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6" />
      <path d="M10 11v6M14 11v6" />
    </Icon>
  )
}

export function DownloadIcon(): JSX.Element {
  return <Icon><path d="M12 3v12" /><path d="m7 10 5 5 5-5" /><path d="M5 21h14" /></Icon>
}

export function PlusIcon(): JSX.Element {
  return <Icon><path d="M12 5v14M5 12h14" /></Icon>
}

export function DiskIcon(): JSX.Element {
  return (
    <Icon>
      <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
      <path d="M17 21v-8H7v8M7 3v5h8" />
    </Icon>
  )
}

// Continue: a play triangle — clone this entry into a fresh row.
export function ContinueIcon(): JSX.Element {
  return <Icon><path d="M7 5v14l11-7z" /></Icon>
}

// Prolong: a clock — set this entry's end to now.
export function ProlongIcon(): JSX.Element {
  return <Icon><circle cx="12" cy="12" r="8" /><path d="M12 8v4l3 2" /></Icon>
}

// Info: an "i" in a circle — show the summary for this entry's ticket.
export function InfoIcon(): JSX.Element {
  return <Icon><circle cx="12" cy="12" r="9" /><path d="M12 11v5" /><path d="M12 7.5h.01" /></Icon>
}

// Refresh: a circular arrow — reload the worklog entries.
export function RefreshIcon(): JSX.Element {
  return <Icon><path d="M21 12a9 9 0 1 1-2.64-6.36" /><path d="M21 4v5h-5" /></Icon>
}

// Reset: an undo arrow — discard a row's unsaved edits / restore the DB state.
export function ResetIcon(): JSX.Element {
  return <Icon><path d="M9 14 4 9l5-5" /><path d="M4 9h11a5 5 0 0 1 0 10h-1" /></Icon>
}

// Kebab (vertical dots): the collapsed row-actions menu trigger. The shared Icon
// shell is stroke-based (fill:none), so the dots fill themselves explicitly —
// stroked circles would render as hollow rings.
export function KebabIcon(): JSX.Element {
  return <Icon><circle cx="12" cy="5" r="1.8" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r="1.8" fill="currentColor" stroke="none" /><circle cx="12" cy="19" r="1.8" fill="currentColor" stroke="none" /></Icon>
}

// Passkey (person + key): the standard passkey glyph, from Google's Material
// Symbols "passkey" icon (Apache-2.0) — the same mark the platform passkey UIs
// use, so users recognise the button. Fill-based with the Material viewBox, so
// it doesn't share the stroke-based Icon shell above.
export function PasskeyIcon(): JSX.Element {
  return (
    <svg class="passkey-ico" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true">
      <path d="M120-160v-112q0-34 17.5-62.5T184-378q62-31 126-46.5T440-440q20 0 40 1.5t40 4.5q-4 58 21 109.5t73 84.5v80H120ZM760-40l-60-60v-186q-44-13-72-49.5T600-420q0-58 41-99t99-41q58 0 99 41t41 99q0 45-25.5 80T790-290l50 50-60 60 60 60-80 80ZM440-480q-66 0-113-47t-47-113q0-66 47-113t113-47q66 0 113 47t47 113q0 66-47 113t-113 47Zm300 80q17 0 28.5-11.5T780-440q0-17-11.5-28.5T740-480q-17 0-28.5 11.5T700-440q0 17 11.5 28.5T740-400Z" />
    </svg>
  )
}
