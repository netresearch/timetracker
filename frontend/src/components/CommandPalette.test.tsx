import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AppConfig } from '../config'
import { setPaletteOpen } from '../lib/commandPalette'
import { renderWithProviders } from '../test/renderWithProviders'
import { CommandPalette } from './CommandPalette'

const appConfigStub: AppConfig = {
  locale: 'de', userId: 1, userName: 'x', appTitle: 'TT', roles: ['ROLE_ADMIN'],
  showEmptyLine: false, suggestTime: false, showFuture: false, minEntryDuration: 5, logoutUrl: '/logout',
  csrfToken: '', loginPath: '/login',
}

beforeEach(() => {
  window.APP_CONFIG = { ...appConfigStub }
  setPaletteOpen(false)
})
afterEach(() => {
  cleanup()
  setPaletteOpen(false)
  vi.restoreAllMocks()
})

function mount(): void {
  renderWithProviders(undefined, { route: { initialPath: '/x', component: CommandPalette } })
}

describe('CommandPalette', () => {
  it('is hidden until opened, then exposes a searchable command list', async () => {
    mount()
    expect(screen.queryByRole('combobox')).toBeNull()

    setPaletteOpen(true)
    await waitFor(() => expect(screen.getByRole('combobox')).toBeInTheDocument())
    // Global nav + app commands (month/tracking/auswertung/admin/settings/help/theme/density/logout).
    expect(screen.getAllByRole('option').length).toBeGreaterThan(5)
  })

  it('runs the theme command by clicking the shared header toggle button', async () => {
    const themeButton = document.createElement('button')
    themeButton.id = 'theme-cycle'
    const onClick = vi.fn()
    themeButton.addEventListener('click', onClick)
    document.body.appendChild(themeButton)

    mount()
    setPaletteOpen(true)
    await waitFor(() => expect(screen.getByRole('combobox')).toBeInTheDocument())

    const themeItem = document.getElementById('command-theme')
    expect(themeItem).not.toBeNull()
    fireEvent.mouseDown(themeItem!)
    expect(onClick).toHaveBeenCalled()
    // runCommand always closes the palette after running — no lingering overlay.
    await waitFor(() => expect(screen.queryByRole('combobox')).toBeNull())

    themeButton.remove()
  })

  it('filters to nothing and shows the empty state for an unmatched query', async () => {
    mount()
    setPaletteOpen(true)
    await waitFor(() => expect(screen.getByRole('combobox')).toBeInTheDocument())

    fireEvent.input(screen.getByRole('combobox'), { target: { value: 'zzzz-no-such-command' } })
    await waitFor(() => expect(screen.queryAllByRole('option').length).toBe(0))
    expect(document.querySelector('.command-empty')).not.toBeNull()
  })
})
