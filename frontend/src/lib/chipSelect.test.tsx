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
const field: FieldDef = { name: 'customer', label: () => 'Customer', type: 'select', source: 'customers' }

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
})
