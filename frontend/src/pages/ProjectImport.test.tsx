import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import { renderWithProviders } from '../test/renderWithProviders'
import type { Proposal } from '../api/projectImport'

const confirmProjectImport = vi.fn()
const getJson = vi.fn()

// One confident row (a Tempo derivation that matches an existing customer) and
// one red 'ambiguous' row (no confident customer — the admin must pick).
const PROPOSALS: Proposal[] = [
  {
    jira_key: 'SRVMO',
    project_id: 20350,
    project_name: 'Server Monitoring',
    jira_id_prefix: 'SRVMO',
    derived_customer_name: 'Netresearch',
    derived_customer_key: 'NR',
    derivation_source: 'tempo',
    candidate_customers: [],
  },
  {
    jira_key: 'NRFE',
    project_id: 10212,
    project_name: 'NR Frontend',
    jira_id_prefix: 'NRFE',
    derived_customer_name: null,
    derived_customer_key: null,
    derivation_source: 'ambiguous',
    candidate_customers: ['Netresearch [NR]', 'Netresearch Solutions [NRSO]'],
  },
]

vi.mock('../api/projectImport', () => ({
  confirmProjectImport: (...args: unknown[]) => confirmProjectImport(...args),
  proposalsQuery: (ticketSystemId: number) => ({
    queryKey: ['project-import', 'proposals', ticketSystemId],
    queryFn: () => Promise.resolve({ ticket_system_id: ticketSystemId, proposals: PROPOSALS }),
    enabled: ticketSystemId > 0,
  }),
  projectImportKeys: {
    proposals: ['project-import', 'proposals'],
  },
}))

vi.mock('../api/client', () => ({
  apiErrorMessage: (error: unknown, fallback: string) =>
    error instanceof Error && error.message ? error.message : fallback,
  getJson: (...args: unknown[]) => getJson(...args),
}))

const { default: ProjectImport } = await import('./ProjectImport')

// The ticket-system and customer reference dropdowns read /getTicketSystems and
// /getAllCustomers (row-wrapped, per the queries.ts option-source contract).
function mockRefs(): void {
  getJson.mockImplementation((path: string) => {
    if (path === '/getTicketSystems') {
      return Promise.resolve([{ ticketSystem: { id: 1, name: 'Jira Cloud', active: true } }])
    }
    if (path === '/getAllCustomers') {
      return Promise.resolve([
        { customer: { id: 5, name: 'Netresearch', active: true } },
        { customer: { id: 6, name: 'Netresearch Solutions', active: true } },
      ])
    }

    return Promise.resolve([])
  })
}

const FULL_ROLES = ['ROLE_USER', 'ROLE_PL', 'ROLE_ADMIN']

afterEach(() => {
  cleanup()
  confirmProjectImport.mockReset()
  getJson.mockReset()
  window.APP_CONFIG!.roles = [...FULL_ROLES]
})

// Select the ticket system (now a searchable combobox) so the proposals table
// loads: open its trigger and pick the seeded option.
async function selectTicketSystem(): Promise<void> {
  fireEvent.click(screen.getByRole('button', { name: 'Ticket system' }))
  fireEvent.click(await screen.findByRole('option', { name: 'Jira Cloud' }))
  await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument())
}

describe('ProjectImport', () => {
  it('renders the parked prefixes with their derivation badges', async () => {
    mockRefs()
    const { container } = renderWithProviders(() => <ProjectImport />)
    await selectTicketSystem()

    expect(screen.getByText('SRVMO')).toBeInTheDocument()
    expect(screen.getByText('NRFE')).toBeInTheDocument()
    expect(screen.getByText('Tempo account')).toBeInTheDocument()
    expect(screen.getByText('Ambiguous')).toBeInTheDocument()
    // The ambiguous row surfaces its competing candidates as a hint.
    expect(screen.getByText(/Netresearch Solutions \[NRSO\]/)).toBeInTheDocument()

    expect(await axe(container)).toHaveNoViolations()
  })

  it("disables a red 'ambiguous' row's confirm until a customer is chosen", async () => {
    mockRefs()
    renderWithProviders(() => <ProjectImport />)
    await selectTicketSystem()

    const confirm = screen.getByLabelText('Confirm — NRFE') as HTMLInputElement
    expect(confirm).toBeDisabled()

    // Pick an existing customer for the ambiguous row → confirm becomes enabled.
    fireEvent.change(screen.getByLabelText('Customer — NRFE'), { target: { value: '6' } })
    await waitFor(() => expect(confirm).not.toBeDisabled())
  })

  it("keeps a confident 'tempo' row confirmable (its derived customer is preselected)", async () => {
    mockRefs()
    renderWithProviders(() => <ProjectImport />)
    await selectTicketSystem()

    const confirm = screen.getByLabelText('Confirm — SRVMO') as HTMLInputElement
    await waitFor(() => expect(confirm).not.toBeDisabled())
  })

  it('posts the checked rows to confirm with the resolved customer', async () => {
    mockRefs()
    confirmProjectImport.mockResolvedValue({
      projects: [
        {
          jira_key: 'SRVMO',
          project_id: 100,
          project_name: 'Server Monitoring',
          customer_id: 5,
          customer_name: 'Netresearch',
          ticket_system_id: 1,
          status: 'created',
        },
      ],
    })
    const { queryClient } = renderWithProviders(() => <ProjectImport />)
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')
    await selectTicketSystem()

    // Check the confident row, then confirm.
    const confirm = screen.getByLabelText('Confirm — SRVMO') as HTMLInputElement
    await waitFor(() => expect(confirm).not.toBeDisabled())
    fireEvent.input(confirm, { target: { checked: true } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm selected' }))

    await waitFor(() => expect(confirmProjectImport).toHaveBeenCalledTimes(1))
    expect(confirmProjectImport).toHaveBeenLastCalledWith([
      { jira_key: 'SRVMO', project_name: 'Server Monitoring', ticket_system_id: 1, customer_id: 5 },
    ])
    // The confirmed prefixes are refreshed away, and the row's result badge shows.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['project-import', 'proposals'] })
    await waitFor(() => expect(screen.getByText('Created')).toBeInTheDocument())
  })

  it('sends the stable Tempo key when the confirmed new customer is still the derived one', async () => {
    // Customer list WITHOUT 'Netresearch' → the derived 'Netresearch' has no
    // existing match, so the row defaults to a NEW customer keeping the derived
    // name, and its stable Tempo key (NR) rides along (ADR-026 P2).
    getJson.mockImplementation((path: string) => {
      if (path === '/getTicketSystems') {
        return Promise.resolve([{ ticketSystem: { id: 1, name: 'Jira Cloud', active: true } }])
      }
      if (path === '/getAllCustomers') {
        return Promise.resolve([{ customer: { id: 6, name: 'Netresearch Solutions', active: true } }])
      }

      return Promise.resolve([])
    })
    confirmProjectImport.mockResolvedValue({ projects: [] })
    renderWithProviders(() => <ProjectImport />)
    await selectTicketSystem()

    const confirm = screen.getByLabelText('Confirm — SRVMO') as HTMLInputElement
    await waitFor(() => expect(confirm).not.toBeDisabled())
    fireEvent.input(confirm, { target: { checked: true } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm selected' }))

    await waitFor(() => expect(confirmProjectImport).toHaveBeenCalledTimes(1))
    expect(confirmProjectImport).toHaveBeenLastCalledWith([
      {
        jira_key: 'SRVMO',
        project_name: 'Server Monitoring',
        ticket_system_id: 1,
        customer_name: 'Netresearch',
        customer_key: 'NR',
      },
    ])
  })

  it('omits the Tempo key when the admin types a different new name', async () => {
    mockRefs()
    confirmProjectImport.mockResolvedValue({ projects: [] })
    renderWithProviders(() => <ProjectImport />)
    await selectTicketSystem()

    // Override the confident row to a hand-typed different new name — no key.
    fireEvent.change(screen.getByLabelText('Customer — SRVMO'), { target: { value: 'new' } })
    fireEvent.input(screen.getByLabelText('New customer name — SRVMO'), {
      target: { value: 'Totally Different Ltd' },
    })
    const confirm = screen.getByLabelText('Confirm — SRVMO') as HTMLInputElement
    await waitFor(() => expect(confirm).not.toBeDisabled())
    fireEvent.input(confirm, { target: { checked: true } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm selected' }))

    await waitFor(() => expect(confirmProjectImport).toHaveBeenCalledTimes(1))
    expect(confirmProjectImport).toHaveBeenLastCalledWith([
      { jira_key: 'SRVMO', project_name: 'Server Monitoring', ticket_system_id: 1, customer_name: 'Totally Different Ltd' },
    ])
  })

  it('does not expose the screen to a non-admin', () => {
    mockRefs()
    window.APP_CONFIG!.roles = ['ROLE_USER']
    renderWithProviders(() => <ProjectImport />)

    expect(screen.queryByText('Project import')).not.toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
