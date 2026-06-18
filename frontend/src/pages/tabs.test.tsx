import { createMemoryHistory, MemoryRouter, Route } from '@solidjs/router'
import { QueryClient, QueryClientProvider } from '@tanstack/solid-query'
import { fireEvent, render, waitFor } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { axe } from 'vitest-axe'

import Billing from './Billing'
import { BulkEntryForm } from '../components/BulkEntryForm'
import Help from './Help'
import Settings from './Settings'

const getJson = vi.fn()
const postForm = vi.fn()

vi.mock('../api/client', () => ({
  SessionExpiredError: class extends Error {},
  ApiError: class extends Error {
    constructor(public status: number, message: string) {
      super(message)
    }
  },
  getJson: (...args: unknown[]) => getJson(...args),
  postForm: (...args: unknown[]) => postForm(...args),
}))

function renderPage(path: string, component: () => unknown) {
  const history = createMemoryHistory()
  history.set({ value: path })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(() => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter history={history}>
        <Route path={path} component={component as never} />
      </MemoryRouter>
    </QueryClientProvider>
  ))
}

afterEach(() => {
  getJson.mockReset()
  postForm.mockReset()
})

describe('Billing', () => {
  it('builds the export URL from the query-string route and updates on change', async () => {
    getJson.mockImplementation((path: string) => {
      if (path === '/getAllUsers') return Promise.resolve([{ user: { id: 7, username: 'dev' } }])

      return Promise.resolve([])
    })

    const { getByText, getByRole, unmount } = renderPage('/billing', Billing)
    await waitFor(() => expect(getByRole('link', { name: /Export/i })).toBeInTheDocument())

    const url = new URL((getByRole('link', { name: /Export/i }) as HTMLAnchorElement).href, 'https://x')
    expect(url.pathname).toBe('/controlling/export')
    // Defaults: all filters 0, current year, previous month, no flags.
    expect(url.searchParams.get('userid')).toBe('0')
    expect(url.searchParams.get('billable')).toBe('0')
    expect(url.searchParams.get('tickettitles')).toBe('0')

    const billable = getByText(/Limit export/i).closest('label')?.querySelector('input') as HTMLInputElement
    fireEvent.click(billable)
    const updated = new URL((getByRole('link', { name: /Export/i }) as HTMLAnchorElement).href, 'https://x')
    expect(updated.searchParams.get('billable')).toBe('1')

    unmount()
  })
})

describe('Settings', () => {
  it('posts the form-urlencoded settings and reports success', async () => {
    postForm.mockResolvedValue(JSON.stringify({ success: true, locale: 'en', message: 'ok' }))

    const { getByText, getByRole, unmount } = renderPage('/settings', Settings)
    fireEvent.submit(getByRole('button', { name: /Save/i }).closest('form') as HTMLFormElement)

    await waitFor(() => expect(getByText('Settings saved.')).toBeInTheDocument())
    expect(postForm).toHaveBeenCalledWith('/settings/save', expect.objectContaining({
      locale: 'en',
      show_empty_line: 0,
      suggest_time: 0,
      show_future: 0,
    }))

    unmount()
  })
})

describe('Bulk entry form', () => {
  it('blocks submit and shows a message when no preset is chosen', async () => {
    getJson.mockResolvedValue([])
    const { getByText, getByRole, unmount } = renderPage('/bulk', () => <BulkEntryForm />)

    fireEvent.submit(getByRole('button', { name: /Create entries/i }).closest('form') as HTMLFormElement)

    await waitFor(() => expect(getByText('Please choose a preset.')).toBeInTheDocument())
    expect(postForm).not.toHaveBeenCalled()

    unmount()
  })

  it('posts lowercase keys with HH:MM:SS times when valid', async () => {
    getJson.mockResolvedValue([{ preset: { id: 3, name: 'Standard' } }])
    postForm.mockResolvedValue('2 entries have been added')

    const { container, getByText, getByRole, unmount } = renderPage('/bulk', () => <BulkEntryForm />)
    await waitFor(() => expect(getByText('Standard')).toBeInTheDocument())

    const select = container.querySelector('select') as HTMLSelectElement
    select.value = '3'
    fireEvent.input(select)
    const dates = container.querySelectorAll<HTMLInputElement>('input[type=date]')
    fireEvent.input(dates[0]!, { target: { value: '2026-06-01' } })
    fireEvent.input(dates[1]!, { target: { value: '2026-06-05' } })

    fireEvent.submit(getByRole('button', { name: /Create entries/i }).closest('form') as HTMLFormElement)

    await waitFor(() => expect(getByText('2 entries have been added')).toBeInTheDocument())
    expect(postForm).toHaveBeenCalledWith('/tracking/bulkentry', expect.objectContaining({
      preset: 3,
      startdate: '2026-06-01',
      enddate: '2026-06-05',
      usecontract: 1,
    }))

    unmount()
  })
})

describe('Help', () => {
  it('renders shortcut tables without accessibility violations', async () => {
    const { container, getByText, unmount } = renderPage('/help', Help)
    // Global, table-navigation and tracking sections are all present.
    expect(getByText('Alt + 1…7')).toBeInTheDocument()
    expect(getByText('Ctrl + Home / End')).toBeInTheDocument()
    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })
})
