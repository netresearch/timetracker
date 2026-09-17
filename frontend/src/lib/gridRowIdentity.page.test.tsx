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
    expect(document.activeElement).toBe(cellOf(container, 11, 'description'))
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

    expect(document.activeElement).toBe(document.body)
    // The grid still has exactly one tab stop, so it stays reachable by Tab.
    expect(container.querySelectorAll('[tabindex="0"]').length).toBe(1)
    unmount()
  })

  it('marks no row as current when the tracked row id is replaced in place', () => {
    // Tracking reads tr[aria-current="true"] to decide which entry Alt+C clones
    // and Alt+I describes. When a row keeps its DOM node but changes identity —
    // a temp row re-keyed to its persisted id — the stale aria-current would
    // otherwise point both shortcuts at a record the person never selected.
    const [rowId, setRowId] = createSignal(-1)
    const { container, unmount } = render(() => (
      <table class="data-table" use:gridNav={{ items: () => [rowId()] }}>
        <thead><tr><th>Start</th></tr></thead>
        <tbody>
          <tr>
            <td data-row-id={rowId()} data-col-key="description">desc</td>
          </tr>
        </tbody>
      </table>
    ))

    const cell = container.querySelector('td') as HTMLElement
    cell.focus()
    expect(container.querySelector('tr[aria-current="true"]')).not.toBeNull()

    setRowId(11) // same <tr>, different record

    expect(container.querySelector('tr[aria-current="true"]')).toBeNull()
    unmount()
  })

  it('adopts the cursor again when the user tabs into the leftover tab stop', () => {
    // Without this the grid stays pinned to the vanished row and never restores
    // focus again — the guard would have no way out but an arrow key or a click.
    const [rows, setRows] = createSignal<Row[]>(unsaved)
    const { container, unmount } = render(() => <ReorderingGrid rows={rows()} />)
    cellOf(container, -1, 'description').focus()
    setRows(saved)

    const stop = container.querySelector('[tabindex="0"]') as HTMLElement
    stop.focus()
    // A further re-render now restores focus to the row the user actually sits on.
    setRows([{ id: 10, start: '08:00' }, { id: 11, start: '07:00' }])

    expect(document.activeElement).not.toBe(document.body)
    unmount()
  })

  it('does not hand focus to the foreign row on a LATER re-render either', () => {
    const [rows, setRows] = createSignal<Row[]>(unsaved)
    const { container, unmount } = render(() => <ReorderingGrid rows={rows()} />)
    cellOf(container, -1, 'description').focus()

    setRows(saved)
    // A second re-render that recreates the rows — a refetch with a changed
    // payload, a range switch — while focus is still parked on the body. The
    // cursor must still count as gone rather than adopt whatever sits there.
    setRows([{ id: 10, start: '08:00' }, { id: 11, start: '07:00' }])

    expect(document.activeElement).toBe(document.body)
    unmount()
  })

  it('keeps restoring focus by coordinate in a grid that tracks no row ids', () => {
    // The read-only Auswertung table renders no data-row-id at all. It never had
    // the #702 problem and must not lose focus restoration because of the fix.
    const [rows, setRows] = createSignal<Row[]>([{ id: 1, start: '07:00' }, { id: 2, start: '08:00' }])
    const { container, unmount } = render(() => (
      <table class="data-table" use:gridNav={{ items: () => rows() }}>
        <thead><tr><th>Start</th></tr></thead>
        <tbody>
          <For each={rows()}>{(row) => <tr><td>{row.start}</td></tr>}</For>
        </tbody>
      </table>
    ))

    const first = container.querySelector('tbody td') as HTMLElement
    first.focus()
    setRows([{ id: 3, start: '06:00' }, { id: 4, start: '09:00' }])

    expect(document.activeElement).toBe(container.querySelector('tbody td'))
    unmount()
  })
})
