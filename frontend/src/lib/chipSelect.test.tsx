import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { FieldDef, OptionLookup } from '../admin/types'
import { ChipSelect } from './chipSelect'

const customers = [
  { id: 7, label: 'Apollo' },
  { id: 8, label: 'Zeus' },
  { id: 9, label: 'Atlas' },
]
const options: OptionLookup = (source) => (source === 'customers' ? customers : [])
// Required (like the worklog customer cell) → no "clear" pseudo-option.
const field: FieldDef = { name: 'customer', label: () => 'Customer', type: 'select', source: 'customers', required: true }

afterEach(() => { cleanup(); vi.restoreAllMocks() })

describe('ChipSelect (jsdom)', () => {
  it('renders the options and filters as you type', async () => {
    render(() => <ChipSelect field={field} label="Customer" initial={0} options={options} multiple={false} onCommit={vi.fn()} onCancel={vi.fn()} />)

    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))
    fireEvent.input(screen.getByRole('combobox'), { target: { value: 'Ap' } })
    await waitFor(() => {
      const labels = screen.getAllByRole('option').map((o) => o.textContent)
      expect(labels.some((l) => l?.includes('Apollo'))).toBe(true)
      expect(labels.some((l) => l?.includes('Zeus'))).toBe(false)
    })
  })

  it('picking an option commits its numeric id', async () => {
    const onCommit = vi.fn()
    render(() => <ChipSelect field={field} label="Customer" initial={0} options={options} multiple={false} onCommit={onCommit} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    fireEvent.click(screen.getByRole('option', { name: 'Zeus' }))
    await waitFor(() => expect(onCommit).toHaveBeenCalledWith(8, expect.anything()))
  })

  it('Enter on the freshly-opened list never commits the empty value', async () => {
    // Regression: `open` seeded false made the first Enter read the open list as
    // closed and commit selected()=0 instead of selecting the highlighted option.
    const onCommit = vi.fn()
    render(() => <ChipSelect field={field} label="Customer" initial={0} options={options} multiple={false} onCommit={onCommit} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Enter' })
    await Promise.resolve()
    expect(onCommit).not.toHaveBeenCalledWith(0, expect.anything())
  })

  it('Escape cancels the edit without committing', async () => {
    const onCommit = vi.fn()
    const onCancel = vi.fn()
    render(() => <ChipSelect field={field} label="Customer" initial={7} options={options} multiple={false} onCommit={onCommit} onCancel={onCancel} />)
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Escape' })
    expect(onCancel).toHaveBeenCalledTimes(1)
    expect(onCommit).not.toHaveBeenCalled()
  })

  it('a string-valued single select round-trips its string, never NaN', async () => {
    // Regression: forcing the value through Number() turned 'DEV' into NaN and
    // committed the literal 'NaN' for the user `type` / `locale` columns.
    const typeField: FieldDef = {
      name: 'type', label: () => 'Type', type: 'select', stringValue: true,
      staticOptions: [{ value: 'DEV', label: () => 'DEV' }, { value: 'PL', label: () => 'PL' }, { value: 'CTL', label: () => 'CTL' }],
    }
    const noOptions: OptionLookup = () => []
    const onCommit = vi.fn()
    render(() => <ChipSelect field={typeField} label="Type" initial="DEV" options={noOptions} multiple={false} onCommit={onCommit} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    // The existing value is preserved and shown as the checked option (not lost to NaN).
    expect(screen.getByRole('option', { name: 'DEV' })).toHaveAttribute('data-state', 'checked')
    fireEvent.click(screen.getByRole('option', { name: 'PL' }))
    await waitFor(() => expect(onCommit).toHaveBeenCalledWith('PL', expect.anything()))
  })

  it('an optional single relation offers a "none" option that commits 0', async () => {
    const tsField: FieldDef = { name: 'ticket_system', label: () => 'Ticket system', type: 'select', source: 'ticketSystems' }
    const tsOptions: OptionLookup = (source) => (source === 'ticketSystems' ? [{ id: 5, label: 'Jira' }, { id: 6, label: 'GitHub' }] : [])
    const onCommit = vi.fn()
    render(() => <ChipSelect field={tsField} label="Ticket system" initial={5} options={tsOptions} multiple={false} onCommit={onCommit} onCancel={vi.fn()} />)
    // 2 real options + the prepended clear option.
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    fireEvent.click(screen.getByRole('option', { name: /None/ }))
    await waitFor(() => expect(onCommit).toHaveBeenCalledWith(0, expect.anything()))
  })

  const teamsField: FieldDef = { name: 'teams', label: () => 'Teams', type: 'multiselect', source: 'teams' }
  const teams = [{ id: 1, label: 'Backend' }, { id: 2, label: 'Frontend' }, { id: 3, label: 'Design' }]
  const teamOptions: OptionLookup = (source) => (source === 'teams' ? teams : [])

  it('multi: shows the initial selection as chips and adds another on pick', async () => {
    render(() => <ChipSelect field={teamsField} label="Teams" initial={[1]} options={teamOptions} multiple onCommit={vi.fn()} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    expect(screen.getAllByRole('listitem').length).toBe(1) // initial chip (id 1 = Backend)
    fireEvent.click(screen.getByRole('option', { name: 'Design' })) // add id 3 (stays open for multi)
    await waitFor(() => expect(screen.getAllByRole('listitem').length).toBe(2))
  })

  it('multi: removing a chip with its × drops it from the selection', async () => {
    render(() => <ChipSelect field={teamsField} label="Teams" initial={[1, 2]} options={teamOptions} multiple onCommit={vi.fn()} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('listitem').length).toBe(2))

    fireEvent.click(screen.getByRole('button', { name: /Backend/ })) // the × on the Backend chip
    await waitFor(() => expect(screen.getAllByRole('listitem').length).toBe(1))
  })

  it('multi: commits the selected ids as a number[] on Tab (add)', async () => {
    // The committed-array path the deleted InlineMultiSelect tests used to guard:
    // multi commits not on pick but on the deferred finish (Tab / blur / Enter-closed).
    const onCommit = vi.fn()
    render(() => <ChipSelect field={teamsField} label="Teams" initial={[1]} options={teamOptions} multiple onCommit={onCommit} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(3))

    fireEvent.click(screen.getByRole('option', { name: 'Design' })) // add id 3
    await waitFor(() => expect(screen.getAllByRole('listitem').length).toBe(2))
    fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Tab' })
    await waitFor(() => expect(onCommit).toHaveBeenCalledWith([1, 3], expect.anything()))
  })

  it('multi: removing the last chip commits an empty array on Tab', async () => {
    const onCommit = vi.fn()
    render(() => <ChipSelect field={teamsField} label="Teams" initial={[1]} options={teamOptions} multiple onCommit={onCommit} onCancel={vi.fn()} />)
    await waitFor(() => expect(screen.getAllByRole('listitem').length).toBe(1))

    fireEvent.click(screen.getByRole('button', { name: /Backend/ }))
    await waitFor(() => expect(screen.queryAllByRole('listitem').length).toBe(0))
    fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Tab' })
    await waitFor(() => expect(onCommit).toHaveBeenCalledWith([], expect.anything()))
  })
})
