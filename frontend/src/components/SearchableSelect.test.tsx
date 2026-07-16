import { cleanup, fireEvent, render, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { NamedOption } from '../api/queries'
import { SearchableSelect } from './SearchableSelect'

const customers: NamedOption[] = [
  { id: 7, label: 'Apollo' },
  { id: 8, label: 'Zeus' },
  { id: 9, label: 'Atlas' },
]

const teams: NamedOption[] = [
  { id: 1, label: 'Backend' },
  { id: 2, label: 'Frontend' },
  { id: 3, label: 'Design' },
]

afterEach(() => { cleanup(); vi.restoreAllMocks() })

describe('SearchableSelect (jsdom)', () => {
  it('single: opening the control lists the options plus the "all" entry', async () => {
    render(() => <SearchableSelect label="Customer" value={0} onChange={vi.fn()} options={customers} allLabel="All" />)

    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    // 3 real options + the prepended "all" entry.
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(4))
  })

  it('single: typing filters the options', async () => {
    render(() => <SearchableSelect label="Customer" value={0} onChange={vi.fn()} options={customers} allLabel="All" />)
    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(4))

    fireEvent.input(screen.getByRole('combobox'), { target: { value: 'Ap' } })
    await waitFor(() => {
      const labels = screen.getAllByRole('option').map((o) => o.textContent)
      expect(labels.some((l) => l?.includes('Apollo'))).toBe(true)
      expect(labels.some((l) => l?.includes('Zeus'))).toBe(false)
    })
  })

  it('single: picking an option commits its numeric id', async () => {
    const onChange = vi.fn()
    render(() => <SearchableSelect label="Customer" value={0} onChange={onChange} options={customers} allLabel="All" />)
    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(4))

    fireEvent.click(screen.getByRole('option', { name: 'Zeus' }))
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(8))
  })

  it('single: shows the current value in the input and the "all" entry clears it to 0', async () => {
    const onChange = vi.fn()
    render(() => <SearchableSelect label="Customer" value={7} onChange={onChange} options={customers} allLabel="All" />)

    // Single shows the chosen value IN the input (React-Select single), not as a chip.
    expect(screen.getByRole('combobox', { name: 'Customer' })).toHaveValue('Apollo')
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument()

    // Opening lists every option (the selection highlights, it does not pre-filter):
    // 3 real options + the prepended "all" entry.
    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(4))

    fireEvent.click(screen.getByRole('option', { name: 'All' }))
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(0))
  })

  it('single: reverts the input to the selected label when closed without a pick', async () => {
    render(() => <SearchableSelect label="Customer" value={7} onChange={vi.fn()} options={customers} allLabel="All" />)

    const input = screen.getByRole('combobox', { name: 'Customer' })
    expect(input).toHaveValue('Apollo')

    // Open, type a non-committed query (filters the list) …
    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    fireEvent.input(input, { target: { value: 'Ze' } })
    await waitFor(() => expect(input).toHaveValue('Ze'))

    // … then close without picking (toggle the ▾): the input reverts to the chosen
    // label, the typed query does not persist.
    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    await waitFor(() => expect(input).toHaveValue('Apollo'))
  })

  it('single: with no selection, shows an empty search control and no "all" entry', () => {
    render(() => <SearchableSelect label="Customer" value={0} onChange={vi.fn()} options={customers} />)

    // Nothing chosen → no value chip, just the search input + ▾ trigger; and with no
    // allLabel requested, the "all" entry is not offered.
    expect(screen.getByRole('button', { name: 'Customer' })).toBeInTheDocument()
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument()
    expect(screen.queryByText('All')).not.toBeInTheDocument()
  })

  it('multi: shows the initial selection as chips and adds another on pick', async () => {
    const onChange = vi.fn()
    render(() => <SearchableSelect label="Teams" value={[1]} onChange={onChange} options={teams} multiple />)

    expect(screen.getAllByRole('listitem')).toHaveLength(1) // initial chip (id 1 = Backend)
    fireEvent.click(screen.getByRole('button', { name: 'Teams' })) // ▾ opens the list
    fireEvent.input(screen.getByRole('combobox'), { target: { value: 'Des' } })
    await waitFor(() => expect(screen.getByRole('option', { name: 'Design' })).toBeInTheDocument())

    fireEvent.click(screen.getByRole('option', { name: 'Design' }))
    await waitFor(() => expect(onChange).toHaveBeenCalledWith([1, 3]))
  })

  it('multi: removing a chip with its × commits the selection without it', async () => {
    const onChange = vi.fn()
    render(() => <SearchableSelect label="Teams" value={[1, 2]} onChange={onChange} options={teams} multiple />)

    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    fireEvent.click(screen.getByRole('button', { name: /Backend/ })) // the × on the Backend chip
    await waitFor(() => expect(onChange).toHaveBeenCalledWith([2]))
  })

  it('multi: no "all" pseudo-option is offered', async () => {
    render(() => <SearchableSelect label="Teams" value={[]} onChange={vi.fn()} options={teams} multiple allLabel="All" />)

    fireEvent.click(screen.getByRole('button', { name: 'Teams' })) // ▾ opens the list
    fireEvent.input(screen.getByRole('combobox'), { target: { value: 'e' } }) // matches Backend/Frontend/Design
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(3))
    expect(screen.queryByRole('option', { name: 'All' })).not.toBeInTheDocument()
  })
})
