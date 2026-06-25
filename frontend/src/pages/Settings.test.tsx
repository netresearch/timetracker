import { fireEvent, render } from '@solidjs/testing-library'
import { afterEach, describe, expect, it } from 'vitest'

import Settings from './Settings'

afterEach(() => {
  localStorage.clear()
  document.documentElement.removeAttribute('data-font')
  document.documentElement.style.removeProperty('--font-scale')
})

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

  it('applies the body-font preference to <html> on change', () => {
    const { container, unmount } = render(() => <Settings />)

    const fontSelect = Array.from(container.querySelectorAll('select')).find((select) =>
      Array.from(select.options).some((option) => option.value === 'dyslexic'),
    ) as HTMLSelectElement
    fireEvent.change(fontSelect, { target: { value: 'dyslexic' } })

    expect(document.documentElement.getAttribute('data-font')).toBe('dyslexic')
    expect(localStorage.getItem('timetracker-font')).toBe('dyslexic')

    unmount()
  })
})
