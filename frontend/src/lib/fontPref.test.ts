import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import {
  applyFontPreferences,
  getFontFamily,
  getFontSize,
  setFontFamily,
  setFontSize,
} from './fontPref'

const root = document.documentElement

beforeEach(() => {
  localStorage.clear()
  root.removeAttribute('data-font')
  root.style.removeProperty('--font-scale')
})

afterEach(() => {
  localStorage.clear()
  root.removeAttribute('data-font')
  root.style.removeProperty('--font-scale')
})

describe('fontPref', () => {
  it('defaults to the Hyperlegible body face and normal size', () => {
    expect(getFontFamily()).toBe('default')
    expect(getFontSize()).toBe('normal')
  })

  it('applies and persists a body-font choice on <html>', () => {
    setFontFamily('dyslexic')

    expect(root.getAttribute('data-font')).toBe('dyslexic')
    expect(localStorage.getItem('timetracker-font')).toBe('dyslexic')
    expect(getFontFamily()).toBe('dyslexic')
  })

  it('clears the attribute and storage when returning to the default face', () => {
    setFontFamily('system')
    setFontFamily('default')

    expect(root.hasAttribute('data-font')).toBe(false)
    expect(localStorage.getItem('timetracker-font')).toBeNull()
  })

  it('maps the text-size choice to the --font-scale multiplier', () => {
    setFontSize('large')
    expect(root.style.getPropertyValue('--font-scale')).toBe('1.15')
    expect(localStorage.getItem('timetracker-font-scale')).toBe('1.15')
    expect(getFontSize()).toBe('large')

    setFontSize('larger')
    expect(root.style.getPropertyValue('--font-scale')).toBe('1.3')

    setFontSize('normal')
    expect(root.style.getPropertyValue('--font-scale')).toBe('')
    expect(localStorage.getItem('timetracker-font-scale')).toBeNull()
  })

  it('re-applies both saved preferences to <html>', () => {
    localStorage.setItem('timetracker-font', 'dyslexic')
    localStorage.setItem('timetracker-font-scale', '1.3')

    applyFontPreferences()

    expect(root.getAttribute('data-font')).toBe('dyslexic')
    expect(root.style.getPropertyValue('--font-scale')).toBe('1.3')
  })
})
