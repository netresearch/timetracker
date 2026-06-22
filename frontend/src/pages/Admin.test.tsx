import { cleanup, fireEvent, screen, waitFor, within } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { ApiError } from '../api/client'
import { renderWithProviders } from '../test/renderWithProviders'
import Admin from './Admin'

const getJson = vi.fn()
const postJson = vi.fn()

vi.mock('../api/client', () => {
  class MockApiError extends Error {
    constructor(public status: number, message: string) {
      super(message)
      this.name = 'ApiError'
    }
  }

  return {
    SessionExpiredError: class extends Error {},
    ApiError: MockApiError,
    apiErrorMessage: (error: unknown, fallback: string) => (error instanceof MockApiError ? error.message : fallback),
    getJson: (...args: unknown[]) => getJson(...args),
    postJson: (...args: unknown[]) => postJson(...args),
  }
})

function mockEndpoints() {
  getJson.mockImplementation((path: string) => {
    switch (path) {
      case '/getAllCustomers':
        return Promise.resolve([{ customer: { id: 1, name: 'ACME', active: true, global: false, teams: [2] } }])
      case '/getAllTeams':
        return Promise.resolve([{ team: { id: 2, name: 'Backend', lead_user_id: 3 } }])
      case '/getAllUsers':
        return Promise.resolve([{ user: { id: 3, username: 'jdoe', abbr: 'JD', type: 'DEV', teams: [2] } }])
      case '/getAllProjects':
        return Promise.resolve([{ project: { id: 4, name: 'Site', customer: 1, jiraId: 'ABC', active: true, global: false } }])
      case '/getActivities':
        return Promise.resolve([{ activity: { id: 5, name: 'Dev', needsTicket: false, factor: 1 } }])
      case '/getAllHolidays':
        return Promise.resolve([{ holiday: { id: 20260101, day: '2026-01-01', name: 'New Year' } }])
      case '/getTicketSystems':
        return Promise.resolve([{ ticketSystem: { id: 6, name: 'Jira', type: 'JIRA', bookTime: true, url: 'https://x' } }])
      default:
        return Promise.resolve([])
    }
  })
}

function renderAdmin(path = '/admin') {
  return renderWithProviders(undefined, {
    route: { initialPath: path, path: '/admin/:entity?', component: Admin },
  })
}

// Renders the customer grid (customer 1 has team 2) with the given team
// catalogue, then opens the inline chip-combobox editor on the "Backend" teams cell.
async function openTeamsCombobox(teams: { id: number; name: string }[]) {
  getJson.mockImplementation((path: string) =>
    path === '/getAllCustomers'
      ? Promise.resolve([{ customer: { id: 1, name: 'ACME', active: true, global: false, teams: [2] } }])
      : path === '/getAllTeams'
        ? Promise.resolve(teams.map((team) => ({ team })))
        : Promise.resolve([]),
  )
  const utils = renderAdmin()
  await waitFor(() => expect(utils.getByRole('gridcell', { name: 'Backend' })).toBeInTheDocument())

  const teamsCell = utils.getByRole('gridcell', { name: 'Backend' })
  teamsCell.focus()
  fireEvent.keyDown(teamsCell, { key: 'Enter' })
  await waitFor(() => expect(teamsCell.querySelector('.combobox-input')).not.toBeNull())

  return { ...utils, teamsCell }
}

afterEach(() => {
  cleanup() // unmount even after a throwing test, so a body-portalled combobox + body-inert can't leak into the next test
  getJson.mockReset()
  postJson.mockReset()
  vi.restoreAllMocks()
})

describe('Admin', () => {
  it('lists the first entity (customers) and renders relation columns', async () => {
    mockEndpoints()
    const { getByRole, getByText, unmount } = renderAdmin()

    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())
    // teams relation column resolves id 2 → "Backend"
    expect(getByText('Backend')).toBeInTheDocument()

    unmount()
  })

  it('deep-links to an entity via the URL segment (/admin/users)', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin('/admin/users')

    // The URL segment selects the entity directly — Users renders, not Customers.
    await waitFor(() => expect(getByRole('gridcell', { name: 'jdoe' })).toBeInTheDocument())
    unmount()
  })

  it('switches entity via the sub-nav', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Users' }))
    await waitFor(() => expect(getByRole('gridcell', { name: 'jdoe' })).toBeInTheDocument())

    unmount()
  })

  it('saves a new customer as typed JSON', async () => {
    mockEndpoints()
    postJson.mockResolvedValue([7, 'New', true, false, []])
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Add' }))
    // The dialog is portalled to document.body (Ark UI); screen queries the
    // whole document, and the name field is the dialog's only textbox.
    const name = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(name, { target: { value: 'New' } })
    fireEvent.submit(name.closest('form') as HTMLFormElement)

    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/customer/save', expect.objectContaining({ name: 'New', active: true, global: false })),
    )

    unmount()
  })

  it('does not crash on list rows with a missing or null wrapper', async () => {
    // A prod list row whose rowKey wrapper is absent/null must not crash the
    // grid (regression: it read `.name` off an undefined unwrapped row).
    getJson.mockImplementation((path: string) => {
      switch (path) {
        case '/getAllCustomers':
          return Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: false, teams: [] } },
            { customer: null },
            {},
          ])
        default:
          return Promise.resolve([])
      }
    })
    const { getByRole, queryAllByRole, unmount } = renderAdmin()

    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())
    // Only the one well-formed customer renders; the malformed rows are dropped.
    expect(queryAllByRole('row').length).toBe(2) // header + 1 data row

    unmount()
  })

  it('filters the list by the free-text query', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: true, teams: [] } },
            { customer: { id: 2, name: 'Globex', active: true, global: true, teams: [] } },
          ])
        : Promise.resolve([]),
    )
    const { getByRole, queryByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.input(getByRole('searchbox'), { target: { value: 'glob' } })

    await waitFor(() => expect(queryByRole('cell', { name: 'ACME' })).not.toBeInTheDocument())
    expect(getByRole('gridcell', { name: 'Globex' })).toBeInTheDocument()

    unmount()
  })

  it('deletes a row after the confirm dialog is accepted', async () => {
    mockEndpoints()
    postJson.mockResolvedValue(null)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Delete' }))

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/customer/delete', { id: 1 }))
    unmount()
  })

  it('does not delete when the confirm dialog is cancelled', async () => {
    mockEndpoints()
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Delete' }))

    expect(postJson).not.toHaveBeenCalled()
    unmount()
  })

  it('keeps the dialog open and shows the server error message on save failure', async () => {
    mockEndpoints()
    postJson.mockRejectedValue(new ApiError(422, 'Name taken'))
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Add' }))
    const name = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(name, { target: { value: 'New' } })
    const form = name.closest('form') as HTMLFormElement
    fireEvent.submit(form)

    // The dialog stays open and shows the server error inside the form.
    await waitFor(() => expect(form.querySelector('[role=alert]')).toHaveTextContent('Name taken'))
    expect(form).toBeInTheDocument()
    unmount()
  })

  it('cycles a column header through ascending, descending and unsorted', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const header = getByRole('button', { name: /Name/ })
    const th = () => header.closest('th')
    expect(th()).toHaveAttribute('aria-sort', 'none')
    fireEvent.click(header)
    expect(th()).toHaveAttribute('aria-sort', 'ascending')
    fireEvent.click(header)
    expect(th()).toHaveAttribute('aria-sort', 'descending')
    fireEvent.click(header)
    expect(th()).toHaveAttribute('aria-sort', 'none')
    unmount()
  })

  it('shows the load-error fallback when the list request fails', async () => {
    getJson.mockRejectedValue(new ApiError(500, 'boom'))
    const { findByRole, unmount } = renderAdmin()

    expect(await findByRole('alert')).toBeInTheDocument()
    unmount()
  })

  it('has no automatically detectable accessibility violations', async () => {
    mockEndpoints()
    const { container, getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    expect(await axe(container)).toHaveNoViolations()

    unmount()
  })

  it('exposes the new Projects fields and the read-only subtickets column', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin('/admin/projects')
    await waitFor(() => expect(getByRole('gridcell', { name: 'Site' })).toBeInTheDocument())

    // The auto-synced subtickets column is shown (read-only).
    expect(getByRole('columnheader', { name: /Subtickets/i })).toBeInTheDocument()

    // The edit modal exposes the three new editable fields.
    fireEvent.click(getByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Invoice')).toBeInTheDocument()
    expect(within(dialog).getByText('Internal reference')).toBeInTheDocument()
    expect(within(dialog).getByText('External reference')).toBeInTheDocument()

    unmount()
  })

  it('holidays are immutable: no Edit button, and delete posts the day (not id)', async () => {
    mockEndpoints()
    postJson.mockResolvedValue({ success: true })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { getByRole, queryByRole, unmount } = renderAdmin('/admin/holidays')
    await waitFor(() => expect(getByRole('gridcell', { name: 'New Year' })).toBeInTheDocument())

    // editable:false → the per-row Edit button is hidden (delete still applies).
    expect(queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()

    // Delete keys on the day, not the synthetic numeric id.
    fireEvent.click(getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/holiday/delete', { day: '2026-01-01' }))

    unmount()
  })
})

describe('Admin inline cell editing', () => {
  it('auto-saves a complete row on commit (no need to leave the row)', async () => {
    mockEndpoints() // the customer row has its required name → editing keeps it complete
    postJson.mockResolvedValue([1, 'ACME2', true, false, [2]])
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' })

    const editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(editor, { target: { value: 'ACME2' } })
    fireEvent.keyDown(editor, { key: 'Enter' }) // commit → row complete → auto-save

    // Saved on commit — no row-leave needed.
    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/customer/save', expect.objectContaining({ id: 1, name: 'ACME2', active: true, global: false })),
    )

    unmount()
  })

  // Opening the ACME name cell, then leaving it via `exitKey` without changing
  // the value, must leave the row clean: no save, no warning tint, and the disk
  // (force-save) stays hidden in its reserved slot.
  async function expectRowStaysCleanAfter(exitKey: 'Enter' | 'Escape') {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    const row = cell.closest('tr') as HTMLTableRowElement
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' }) // open the editor (seeds a draft)
    const editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.keyDown(editor, { key: exitKey })

    await waitFor(() => expect(screen.queryByRole('textbox')).not.toBeInTheDocument())
    expect(postJson).not.toHaveBeenCalled()
    expect(row.classList.contains('is-dirty')).toBe(false)
    const disk = row.querySelector('.is-unsaved') as HTMLElement
    expect(disk).not.toBeNull()
    expect(disk.classList.contains('action-slot-hidden')).toBe(true)

    unmount()
  }

  it('committing a cell without changing it leaves the row clean (no save, no dirty cues)', () =>
    expectRowStaysCleanAfter('Enter'))

  it('does not mark the row dirty or save when a cell is opened then cancelled (Escape)', () =>
    expectRowStaysCleanAfter('Escape'))

  it('seeds the editor with the printable key that opened it', async () => {
    mockEndpoints()
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Z' })

    const editor = (await screen.findByRole('textbox')) as HTMLInputElement
    await waitFor(() => expect(editor.value).toBe('Z'))

    unmount()
  })

  it('auto-saves a checkbox edit immediately, without leaving the row', async () => {
    mockEndpoints()
    postJson.mockResolvedValue([1, 'ACME', true, true, [2]])
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    // Toggle the "global" checkbox cell (No → on) on the (complete) ACME row.
    // The boolean cell's accessible name is its visually-hidden Yes/No text.
    const globalCell = getByRole('gridcell', { name: 'No' })
    globalCell.focus()
    fireEvent.keyDown(globalCell, { key: 'Enter' })
    const check = await waitFor(() => {
      const el = globalCell.querySelector<HTMLInputElement>('input[type="checkbox"]')
      if (!el) throw new Error('inline checkbox not mounted yet')

      return el
    })
    fireEvent.click(check)
    fireEvent.keyDown(check, { key: 'Enter' }) // commit → row complete → auto-save

    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/customer/save', expect.objectContaining({ global: true })),
    )

    unmount()
  })

  it('cancels an edit on Escape without saving', async () => {
    mockEndpoints()
    const { getByRole, queryByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' })
    const editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(editor, { target: { value: 'Discarded' } })
    fireEvent.keyDown(editor, { key: 'Escape' })

    await waitFor(() => expect(queryByRole('textbox')).not.toBeInTheDocument())
    expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument()
    ;(getByRole('searchbox') as HTMLElement).focus()
    expect(postJson).not.toHaveBeenCalled()

    unmount()
  })

  it('inline-edits the teams multiselect as a chip combobox (not the modal)', async () => {
    const { teamsCell, unmount } = await openTeamsCombobox([
      { id: 2, name: 'Backend' },
      { id: 3, name: 'Frontend' },
    ])

    // The teams cell opens an inline filterable combobox, not the modal; the
    // current team renders as a chip and the option list shows the catalogue.
    // (add/remove/commit behaviour is unit-tested in chipSelect.test.tsx + e2e.)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(within(teamsCell).getByRole('listitem')).toHaveTextContent('Backend')
    expect(await screen.findByRole('option', { name: 'Frontend' })).toBeInTheDocument()

    unmount()
  })


  it('renders selected teams as read-only chips in display mode (not just when editing)', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([{ customer: { id: 1, name: 'ACME', active: true, global: false, teams: [2] } }])
        : path === '/getAllTeams'
          ? Promise.resolve([{ team: { id: 2, name: 'Backend' } }])
          : Promise.resolve([]),
    )
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'Backend' })).toBeInTheDocument())

    // The teams cell shows a chip without being edited (no editor mounted).
    const cell = getByRole('gridcell', { name: 'Backend' })
    expect(cell.querySelector('.inline-tags.is-readonly .tag')).not.toBeNull()
    expect(cell.querySelector('input, select, button')).toBeNull()

    unmount()
  })

  it('keeps focus on the cell after Enter by default (stay, does not move down)', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: false, teams: [] } },
            { customer: { id: 2, name: 'Globex', active: true, global: false, teams: [] } },
          ])
        : Promise.resolve([]),
    )
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' })
    const editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(editor, { target: { value: 'ACME9' } })
    fireEvent.keyDown(editor, { key: 'Enter' }) // default: commit + stay

    await waitFor(() => expect(screen.queryByRole('textbox')).not.toBeInTheDocument())
    // Focus stayed on the row-1 name cell — it did not drop to Globex (row 2).
    const active = document.activeElement as HTMLElement
    expect(active.getAttribute('data-row-id')).toBe('1')
    expect(active.getAttribute('data-col-key')).toBe('name')
    unmount()
  })

  it('surfaces a failed auto-save error and keeps the edited value (draft retained)', async () => {
    mockEndpoints()
    postJson.mockRejectedValue(new ApiError(422, 'Name taken'))
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' })
    const editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(editor, { target: { value: 'ACME2' } })
    fireEvent.keyDown(editor, { key: 'Enter' }) // commit → auto-save attempt

    // Auto-save fires on the now-complete row; its failure is surfaced (no longer
    // silent) so a genuine server rejection on a complete row isn't hidden.
    await waitFor(() => expect(getByRole('alert')).toHaveTextContent('Name taken'))
    // The edited value is retained (not rolled back) so it isn't lost.
    expect(getByRole('gridcell', { name: 'ACME2' })).toBeInTheDocument()

    unmount()
  })

  it('hands a pending inline draft to the modal instead of stale row data', async () => {
    mockEndpoints()
    // The save fails, so the auto-save keeps the draft dirty (rather than
    // clearing it), letting us verify the still-pending draft seeds the modal.
    postJson.mockRejectedValue(new ApiError(422, 'nope'))
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' })
    const inlineEditor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(inlineEditor, { target: { value: 'ACME-DIRTY' } })
    fireEvent.keyDown(inlineEditor, { key: 'Enter' }) // commit → auto-save (quiet fail → draft kept)
    await waitFor(() => expect(postJson).toHaveBeenCalled())

    // Opening the modal must seed from the still-pending draft (the edited value),
    // not the stale list row.
    fireEvent.click(getByRole('button', { name: 'Edit' }))
    const modalName = (await screen.findByRole('textbox')) as HTMLInputElement
    await waitFor(() => expect(modalName).toHaveValue('ACME-DIRTY'))

    unmount()
  })

  it('does not drop an edit committed while an auto-save is in flight', async () => {
    mockEndpoints()
    let resolveFirst!: () => void
    postJson
      .mockImplementationOnce(() => new Promise<void>((resolve) => { resolveFirst = () => resolve() })) // 1st save hangs
      .mockResolvedValue([1, 'A2', true, false, [2]])
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    // Edit name → A1 → commit. The complete row auto-saves, but the POST hangs.
    const cell = getByRole('gridcell', { name: 'ACME' })
    cell.focus()
    fireEvent.keyDown(cell, { key: 'Enter' })
    let editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(editor, { target: { value: 'A1' } })
    fireEvent.keyDown(editor, { key: 'Enter' })
    await waitFor(() => expect(postJson).toHaveBeenCalledTimes(1))

    // Edit the same cell again → A2 → commit WHILE the first save is still in flight.
    const dirtyCell = getByRole('gridcell', { name: 'A1' })
    dirtyCell.focus()
    fireEvent.keyDown(dirtyCell, { key: 'Enter' })
    editor = (await screen.findByRole('textbox')) as HTMLInputElement
    fireEvent.input(editor, { target: { value: 'A2' } })
    fireEvent.keyDown(editor, { key: 'Enter' })

    // The first save resolves; the A2 edit must NOT be dropped — it re-saves.
    resolveFirst()
    await waitFor(() =>
      expect(postJson).toHaveBeenLastCalledWith('/customer/save', expect.objectContaining({ name: 'A2' })),
    )

    unmount()
  })
})

describe('Admin list — inactive filter, paging, CSV export', () => {
  it('hides inactive records by default and reveals them via the toggle', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([
            { customer: { id: 1, name: 'ActiveCo', active: true, global: false, teams: [] } },
            { customer: { id: 2, name: 'GoneCo', active: false, global: false, teams: [] } },
          ])
        : Promise.resolve([]),
    )
    const { getByRole, queryByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ActiveCo' })).toBeInTheDocument())

    expect(queryByRole('gridcell', { name: 'GoneCo' })).not.toBeInTheDocument()
    fireEvent.click(getByRole('checkbox', { name: 'Show inactive' }))
    await waitFor(() => expect(getByRole('gridcell', { name: 'GoneCo' })).toBeInTheDocument())

    unmount()
  })

  it('pages a long list and Next advances to the following page', async () => {
    const many = Array.from({ length: 75 }, (_, i) => ({
      customer: { id: i + 1, name: `C${String(i + 1).padStart(3, '0')}`, active: true, global: false, teams: [] },
    }))
    getJson.mockImplementation((path: string) => (path === '/getAllCustomers' ? Promise.resolve(many) : Promise.resolve([])))
    const { getByRole, queryByRole, getAllByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'C001' })).toBeInTheDocument())

    // First page renders only PAGE_SIZE (50) data rows + the header row.
    expect(getAllByRole('row').length).toBe(51)
    expect(queryByRole('gridcell', { name: 'C051' })).not.toBeInTheDocument()

    fireEvent.click(getByRole('button', { name: 'Next' }))
    await waitFor(() => expect(getByRole('gridcell', { name: 'C051' })).toBeInTheDocument())
    expect(queryByRole('gridcell', { name: 'C001' })).not.toBeInTheDocument()

    unmount()
  })

  it('exports the rows as CSV and neutralizes formula injection', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([{ customer: { id: 1, name: '=DANGER()', active: true, global: false, teams: [] } }])
        : Promise.resolve([]),
    )
    let blob: Blob | undefined
    vi.spyOn(URL, 'createObjectURL').mockImplementation((b) => {
      blob = b as Blob

      return 'blob:x'
    })
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: '=DANGER()' })).toBeInTheDocument())

    fireEvent.click(getByRole('button', { name: 'Export CSV' }))
    expect(click).toHaveBeenCalled()
    const text = await blob!.text()
    // Leading '=' is neutralized with an apostrophe so spreadsheets won't run it.
    expect(text).toContain("'=DANGER()")

    unmount()
  })
})

describe('Admin list — row selection & bulk actions', () => {
  it('select-all selects every filtered row and shows the count', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: false, teams: [] } },
            { customer: { id: 2, name: 'Globex', active: true, global: false, teams: [] } },
          ])
        : Promise.resolve([]),
    )
    const { getByRole, getByText, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('checkbox', { name: 'Select all rows' }))
    expect(getByRole('checkbox', { name: 'Select ACME' })).toBeChecked()
    expect(getByRole('checkbox', { name: 'Select Globex' })).toBeChecked()
    expect(getByText('2 selected')).toBeInTheDocument()

    unmount()
  })

  it('bulk-deactivates the selected rows (whole-entity save with active=false)', async () => {
    mockEndpoints()
    postJson.mockResolvedValue([])
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('checkbox', { name: 'Select ACME' }))
    fireEvent.click(getByRole('button', { name: 'Deactivate' }))

    await waitFor(() =>
      expect(postJson).toHaveBeenCalledWith('/customer/save', expect.objectContaining({ id: 1, active: false })),
    )
    unmount()
  })

  it('bulk-deletes the selected rows after confirm', async () => {
    mockEndpoints()
    postJson.mockResolvedValue(null)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { getByRole, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('checkbox', { name: 'Select ACME' }))
    // Scope to the bulk bar — the row also has its own "Delete" action button.
    const bulkBar = getByRole('region', { name: 'Bulk actions' })
    fireEvent.click(within(bulkBar).getByRole('button', { name: 'Delete' }))

    await waitFor(() => expect(postJson).toHaveBeenCalledWith('/customer/delete', { id: 1 }))
    unmount()
  })

  it('on a partial bulk failure keeps the failed row selected and shows the error', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/getAllCustomers'
        ? Promise.resolve([
            { customer: { id: 1, name: 'ACME', active: true, global: false, teams: [] } },
            { customer: { id: 2, name: 'Globex', active: true, global: false, teams: [] } },
          ])
        : Promise.resolve([]),
    )
    let calls = 0
    postJson.mockImplementation((path: string) => {
      if (path === '/customer/save') {
        calls += 1

        return calls === 1 ? Promise.resolve([]) : Promise.reject(new ApiError(500, 'boom'))
      }

      return Promise.resolve([])
    })
    const { getByRole, getByText, unmount } = renderAdmin()
    await waitFor(() => expect(getByRole('gridcell', { name: 'ACME' })).toBeInTheDocument())

    fireEvent.click(getByRole('checkbox', { name: 'Select all rows' }))
    fireEvent.click(getByRole('button', { name: 'Deactivate' }))

    // ACME (1st) succeeded → deselected; Globex (2nd) failed → stays selected.
    await waitFor(() => expect(getByRole('alert')).toBeInTheDocument())
    expect(getByText('1 selected')).toBeInTheDocument()
    expect(getByRole('checkbox', { name: 'Select Globex' })).toBeChecked()

    unmount()
  })
})

describe('Admin status page', () => {
  it('renders the diagnostics returned by /admin/status', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/admin/status'
        ? Promise.resolve({
            app: { title: 'TT', env: 'test', debug: false, locale: 'en', version: null },
            php: { version: '8.5.0', extensions: ['intl', 'pdo_mysql'] },
            symfony: { kernel: '7.3.0' },
            packages: { 'doctrine/orm': '3.1.0' },
            database: { driver: 'mysql', platform: 'MariaDBPlatform', serverVersion: '11.4.2-MariaDB', host: 'db', port: '3306', name: 'tt' },
            config: { ldap_host: 'ldap.example', ldap_ssl: true },
          })
        : Promise.resolve([]),
    )
    const { getByRole, getByText, unmount } = renderAdmin('/admin/status')

    await waitFor(() => expect(getByText('8.5.0')).toBeInTheDocument())
    expect(getByRole('heading', { name: 'PHP' })).toBeInTheDocument()
    expect(getByRole('heading', { name: 'Database' })).toBeInTheDocument()
    expect(getByText('MariaDBPlatform')).toBeInTheDocument()
    expect(getByText('11.4.2-MariaDB')).toBeInTheDocument()
    expect(getByText('intl, pdo_mysql')).toBeInTheDocument()
    // boolean config renders Yes/No, not raw true/false
    expect(getByText('Yes')).toBeInTheDocument()

    unmount()
  })

  it('shows an error message when the status request fails', async () => {
    getJson.mockImplementation((path: string) =>
      path === '/admin/status' ? Promise.reject(new ApiError(500, 'boom')) : Promise.resolve([]),
    )
    const { getByRole, unmount } = renderAdmin('/admin/status')

    await waitFor(() => expect(getByRole('alert')).toHaveTextContent(/status information/i))

    unmount()
  })
})
