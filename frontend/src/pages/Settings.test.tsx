import { cleanup, within } from '@solidjs/testing-library'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '../test/renderWithProviders'
import Settings from './Settings'

// The Account section hydrates from GET /api/v2/settings on mount; stub the
// settings API so the shell tests never touch the network (the echo matches the
// APP_CONFIG snapshot, so hydration is a no-op here).
vi.mock('../api/settings', () => ({
  fetchSettings: vi.fn().mockResolvedValue({
    locale: 'en',
    show_empty_line: false,
    suggest_time: false,
    show_future: false,
    min_entry_duration: 5,
    personio_sync_enabled: false,
  }),
  patchSettings: vi.fn(),
}))

// The shell resolves the section from the route, so it mounts under a router
// with the optional :section param (the Admin.test.tsx pattern). Per-section
// behaviour is tested next to each section component (components/settings/*,
// components/SecuritySection.test.tsx) — here only the shell is under test.
function renderSettings(path = '/settings') {
  return renderWithProviders(undefined, {
    route: { initialPath: path, path: '/settings/:section?', component: Settings },
  })
}

afterEach(cleanup)

describe('Settings shell', () => {
  it('renders the account section by default', () => {
    const { getByRole, unmount } = renderSettings()

    expect(getByRole('group', { name: 'Account' })).toBeInTheDocument()

    unmount()
  })

  it('renders the requested section', () => {
    const { getByRole, queryByRole, unmount } = renderSettings('/settings/security')

    expect(getByRole('group', { name: 'Security' })).toBeInTheDocument()
    // Prefix match: the heading's accessible name also carries its help trigger.
    expect(getByRole('heading', { name: /^Two-factor authentication/ })).toBeInTheDocument()
    // Only the active section mounts — account is gone.
    expect(queryByRole('group', { name: 'Account' })).not.toBeInTheDocument()

    unmount()
  })

  it('falls back to account for an unknown section', () => {
    const { getByRole, unmount } = renderSettings('/settings/nope')

    expect(getByRole('group', { name: 'Account' })).toBeInTheDocument()

    unmount()
  })

  it('lists all five sections in the nav', () => {
    const { getByRole, unmount } = renderSettings()

    const nav = getByRole('navigation', { name: 'Settings' })
    const labels = within(nav)
      .getAllByRole('link')
      .map((link) => link.textContent)
    expect(labels).toEqual(['Account & tracking', 'Appearance', 'Security', 'API tokens', 'Synchronization'])

    unmount()
  })

  it('marks the active section with aria-current', () => {
    const { getByRole, unmount } = renderSettings('/settings/appearance')

    const nav = getByRole('navigation', { name: 'Settings' })
    expect(within(nav).getByRole('link', { name: 'Appearance' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).getByRole('link', { name: 'Account & tracking' })).not.toHaveAttribute('aria-current')

    unmount()
  })

  it('marks the fallback section with aria-current when the URL has no section', () => {
    const { getByRole, unmount } = renderSettings()

    const nav = getByRole('navigation', { name: 'Settings' })
    expect(within(nav).getByRole('link', { name: 'Account & tracking' })).toHaveAttribute('aria-current', 'page')

    unmount()
  })
})
