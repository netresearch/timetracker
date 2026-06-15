import { render } from '@solidjs/testing-library'
import { describe, expect, it } from 'vitest'

import Settings from './Settings'

describe('Settings', () => {
  it('only offers the locales the UI actually ships (de/en)', () => {
    const { container, unmount } = render(() => <Settings />)

    // Target the locale select specifically (the page also has a client-side
    // grid-editing preference select).
    const select = container.querySelector('select[name="locale"]') as HTMLSelectElement
    const values = Array.from(select.options).map((option) => option.value)

    expect(values).toEqual(['de', 'en'])
    // The previously offered but never-translated locales must be gone.
    expect(values).not.toContain('es')
    expect(values).not.toContain('fr')
    expect(values).not.toContain('ru')

    unmount()
  })
})
