import { cleanup, fireEvent, render } from '@solidjs/testing-library'
import { afterEach, describe, expect, it } from 'vitest'

import { AppearanceSection } from './AppearanceSection'

afterEach(() => {
  cleanup()
  localStorage.clear()
  document.documentElement.removeAttribute('data-font')
  document.documentElement.style.removeProperty('--font-scale')
})

describe('AppearanceSection', () => {
  it('applies the body-font preference to <html> on change', () => {
    const { container } = render(() => <AppearanceSection />)

    const fontSelect = Array.from(container.querySelectorAll('select')).find((select) =>
      Array.from(select.options).some((option) => option.value === 'dyslexic'),
    ) as HTMLSelectElement
    fireEvent.change(fontSelect, { target: { value: 'dyslexic' } })

    expect(document.documentElement.getAttribute('data-font')).toBe('dyslexic')
    expect(localStorage.getItem('timetracker-font')).toBe('dyslexic')
  })

  it('keeps the instantly-applied device preferences outside any form', () => {
    const { getByRole, getByText } = render(() => <AppearanceSection />)

    const device = getByRole('group', { name: 'Appearance' })
    // The section states its save semantics right under the title.
    expect(getByText(/apply immediately — no Save needed/)).toBeInTheDocument()

    // Device-local preferences are deliberately not part of any form — they
    // persist to localStorage on change, nothing here is submitted.
    expect(device.closest('form')).toBeNull()
    expect(device.querySelector('button[type="submit"]')).toBeNull()
    // All five live here: Enter behavior, date format, font, text size, layout.
    expect(device.querySelectorAll('select')).toHaveLength(5)
  })
})
