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

  it('single: shows the current value and the "all" entry clears it to 0', async () => {
    const onChange = vi.fn()
    render(() => <SearchableSelect label="Customer" value={7} onChange={onChange} options={customers} allLabel="All" />)

    // The chosen value is shown in the (compact) control.
    expect(screen.getByRole('button', { name: 'Customer' })).toHaveTextContent('Apollo')
    fireEvent.click(screen.getByRole('button', { name: 'Customer' }))
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(4))

    fireEvent.click(screen.getByRole('option', { name: 'All' }))
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(0))
  })

  it('single: with no selection and no allLabel, shows the "none" placeholder', () => {
    render(() => <SearchableSelect label="Customer" value={0} onChange={vi.fn()} options={customers} />)

    // No "all" option requested → the compact control falls back to the generic
    // "none" placeholder rather than a blank control.
    expect(screen.getByRole('button', { name: 'Customer' })).toBeInTheDocument()
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
