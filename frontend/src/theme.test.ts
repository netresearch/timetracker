import { afterEach, describe, expect, it, vi } from 'vitest'

import { setThemePreference, themePreference } from './theme'

const KEY = 'timetracker-theme'

afterEach(() => {
  window.localStorage.clear()
  delete document.documentElement.dataset.theme
})

describe('setThemePreference', () => {
  it('applies an explicit theme to <html> and persists it', () => {
    setThemePreference('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(window.localStorage.getItem(KEY)).toBe('dark')
    expect(themePreference()).toBe('dark')

    setThemePreference('light')
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(window.localStorage.getItem(KEY)).toBe('light')
  })

  it('"system" removes the attribute and the stored key', () => {
    setThemePreference('dark')
    setThemePreference('system')
    expect(document.documentElement.dataset.theme).toBeUndefined()
    expect(window.localStorage.getItem(KEY)).toBeNull()
    expect(themePreference()).toBe('system')
  })
})

describe('theme initialisation from storage', () => {
  it('applies a stored preference on module load', async () => {
    window.localStorage.setItem(KEY, 'dark')
    vi.resetModules()
    await import('./theme')
    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('falls back to system for an invalid stored value', async () => {
    window.localStorage.setItem(KEY, 'pink')
    vi.resetModules()
    await import('./theme')
    expect(document.documentElement.dataset.theme).toBeUndefined()
  })
})
