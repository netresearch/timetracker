import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import type { EmployeeMatchProposal } from '../api/personioEmployeeMatch'
import { renderWithProviders } from '../test/renderWithProviders'

const confirmEmployeeMatches = vi.fn()

// One confident e-mail match (pre-checked) and one weaker name match (unchecked).
const PROPOSALS: EmployeeMatchProposal[] = [
  { user_id: 2, username: 'developer', person_id: '900', person_name: 'Dev Eloper', source: 'email' },
  { user_id: 3, username: 'i.myself', person_id: '901', person_name: 'I Myself', source: 'name' },
]

vi.mock('../api/personioEmployeeMatch', () => ({
  confirmEmployeeMatches: (...args: unknown[]) => confirmEmployeeMatches(...args),
  employeeMatchesQuery: () => ({
    queryKey: ['personio-employee-match', 'proposals'],
    queryFn: () => Promise.resolve({ proposals: PROPOSALS }),
  }),
  personioEmployeeMatchKeys: { proposals: ['personio-employee-match', 'proposals'] },
}))

vi.mock('../api/client', () => ({
  apiErrorMessage: (error: unknown, fallback: string) =>
    error instanceof Error && error.message ? error.message : fallback,
}))

const { default: PersonioEmployeeMatch } = await import('./PersonioEmployeeMatch')

const FULL_ROLES = ['ROLE_USER', 'ROLE_PL', 'ROLE_ADMIN']

afterEach(() => {
  cleanup()
  confirmEmployeeMatches.mockReset()
  window.APP_CONFIG!.roles = [...FULL_ROLES]
})

describe('PersonioEmployeeMatch', () => {
  it('lists the unmapped users and pre-checks only the confident e-mail match', async () => {
    const { container } = renderWithProviders(() => <PersonioEmployeeMatch />)

    await waitFor(() => expect(screen.getByText('developer')).toBeInTheDocument())
    expect(screen.getByText('i.myself')).toBeInTheDocument()

    const emailRow = screen.getByLabelText('Confirm — developer') as HTMLInputElement
    const nameRow = screen.getByLabelText('Confirm — i.myself') as HTMLInputElement
    expect(emailRow).toBeChecked()
    expect(nameRow).not.toBeChecked()

    expect(await axe(container)).toHaveNoViolations()
  })

  it('posts only the checked rows to confirm', async () => {
    confirmEmployeeMatches.mockResolvedValue({ applied: [{ user_id: 2, username: 'developer', person_id: '900' }] })

    renderWithProviders(() => <PersonioEmployeeMatch />)
    await waitFor(() => expect(screen.getByText('developer')).toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: 'Confirm selected' }))

    await waitFor(() => expect(confirmEmployeeMatches).toHaveBeenCalledWith([{ user_id: 2, person_id: '900' }]))
  })

  it('includes a name match once the admin checks it', async () => {
    confirmEmployeeMatches.mockResolvedValue({ applied: [] })

    renderWithProviders(() => <PersonioEmployeeMatch />)
    await waitFor(() => expect(screen.getByText('i.myself')).toBeInTheDocument())

    fireEvent.click(screen.getByLabelText('Confirm — i.myself'))
    fireEvent.click(screen.getByRole('button', { name: 'Confirm selected' }))

    await waitFor(() =>
      expect(confirmEmployeeMatches).toHaveBeenCalledWith([
        { user_id: 2, person_id: '900' },
        { user_id: 3, person_id: '901' },
      ]),
    )
  })
})
