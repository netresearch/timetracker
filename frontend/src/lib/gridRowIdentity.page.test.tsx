import { render } from '@solidjs/testing-library'
import { createSignal, For } from 'solid-js'
import { describe, expect, it } from 'vitest'

import { gridNav } from './gridNavigation'

void gridNav

interface Row { id: number; start: string }

// #702: saving a new worklog row that starts EARLIER than the rows already on
// screen moves it down the (newest-first) list. The grid used to restore its
// cursor by DOM coordinates, so the tab stop — and, when the re-render had
// dropped focus, the focus itself — landed on whichever row had taken that
// position: the pre-existing entry. Typing then went into that row's draft and
// auto-saved over its description.
function ReorderingGrid(props: { rows: Row[] }) {
  return (
    <table class="data-table" use:gridNav={{ items: () => props.rows }}>
      <thead><tr><th>Start</th><th>Description</th></tr></thead>
      <tbody>
        <For each={props.rows}>{(row) => (
          <tr>
            <td data-row-id={row.id} data-col-key="start">{row.start}</td>
            <td data-row-id={row.id} data-col-key="description">{`desc-${row.id}`}</td>
          </tr>
        )}</For>
      </tbody>
    </table>
  )
}

const cellOf = (container: HTMLElement, id: number, key: string): HTMLElement =>
  container.querySelector(`td[data-row-id="${id}"][data-col-key="${key}"]`) as HTMLElement

describe('gridNav cursor restoration across a reorder (#702)', () => {
  // The temp row sits on top while unsaved; after the save it sorts by start,
  // newest first, so the 07:00 row drops below the 08:00 one it was added above.
  const unsaved: Row[] = [{ id: -1, start: '07:00' }, { id: 10, start: '08:00' }]
  const saved: Row[] = [{ id: 10, start: '08:00' }, { id: 11, start: '07:00' }]

  it('keeps the tab stop on the same ROW when the rows reorder under it', () => {
    const [rows, setRows] = createSignal<Row[]>([{ id: 11, start: '07:00' }, { id: 10, start: '08:00' }])
    const { container, unmount } = render(() => <ReorderingGrid rows={rows()} />)

    cellOf(container, 11, 'description').focus()
    expect(cellOf(container, 11, 'description').tabIndex).toBe(0)

    setRows(saved) // 11 moves below 10

    expect(cellOf(container, 11, 'description').tabIndex).toBe(0)
    expect(cellOf(container, 10, 'description').tabIndex).toBe(-1)
    unmount()
  })

  it('does not hand focus to a FOREIGN row when the tracked one is gone', () => {
    const [rows, setRows] = createSignal<Row[]>(unsaved)
    const { container, unmount } = render(() => <ReorderingGrid rows={rows()} />)

    // Cursor sits on the unsaved row's description, as it does after the guided
    // fill walks a new row.
    cellOf(container, -1, 'description').focus()

    // The save resolves: the temp row (-1) is replaced by the persisted one (11),
    // which sorts below. The tracked row no longer exists, and the coordinate it
    // used to occupy now belongs to the pre-existing entry. Focus must not be
    // put there — that is what sent the typed description into row 10's draft.
    setRows(saved)

    expect(document.activeElement).not.toBe(cellOf(container, 10, 'description'))
    expect(document.activeElement).not.toBe(cellOf(container, 10, 'start'))
    unmount()
  })
})
