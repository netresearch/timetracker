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
