import { cleanup, fireEvent, screen, waitFor } from '@solidjs/testing-library'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { setShortcutsHelpOpen } from '../lib/shortcutsHelp'
import { renderWithProviders } from '../test/renderWithProviders'
import { ShortcutsDialog } from './ShortcutsDialog'

beforeEach(() => {
  setShortcutsHelpOpen(false)
})
afterEach(() => {
  cleanup()
  setShortcutsHelpOpen(false)
})

function mount(): void {
  renderWithProviders(undefined, { route: { initialPath: '/x', component: ShortcutsDialog } })
}

describe('ShortcutsDialog', () => {
  it('is hidden until opened, then lists shortcuts from every section', async () => {
    mount()
    expect(screen.queryByRole('dialog')).toBeNull()

    setShortcutsHelpOpen(true)
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument())
    // One representative, locale-independent key from each table (global/grid/tracking).
    expect(screen.getByText('Ctrl / ⌘ + K')).toBeInTheDocument()
    expect(screen.getByText('Page ↑ / ↓')).toBeInTheDocument()
    expect(screen.getByText('Alt + P')).toBeInTheDocument()
  })

  it('links through to the full help page', async () => {
    mount()
    setShortcutsHelpOpen(true)
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument())
    expect(screen.getByRole('link')).toBeInTheDocument()
  })

  it('closes via the ✕ button', async () => {
    mount()
    setShortcutsHelpOpen(true)
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument())

    fireEvent.click(screen.getByRole('button'))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  })
})
