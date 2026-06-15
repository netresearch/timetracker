import { afterEach, describe, expect, it, vi } from 'vitest'

import { formatDays, formatDuration, handleShortcut } from './header'

describe('formatDuration', () => {
  it('formats minutes as H:MM like the ExtJS header', () => {
    expect(formatDuration(0)).toBe('0:00')
    expect(formatDuration(65)).toBe('1:05')
    expect(formatDuration(480)).toBe('8:00')
  })

  it('appends person-days above one day when requested', () => {
    expect(formatDuration(480, true)).toBe('8:00')
    expect(formatDuration(960, true)).toBe('16:00 (2 PT)')
    expect(formatDuration(720, true)).toBe('12:00 (1.5 PT)')
    expect(formatDuration(960)).toBe('16:00')
  })
})

describe('formatDays', () => {
  it('formats minutes as person-days only for the Month badge', () => {
    expect(formatDays(0)).toBe('0 PT')
    expect(formatDays(480)).toBe('1 PT')
    expect(formatDays(720)).toBe('1.5 PT')
    expect(formatDays(8880)).toBe('18.5 PT')
  })
})

describe('handleShortcut', () => {
  afterEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
  })

  function setup(): void {
    document.body.innerHTML = `
      <header class="app-header"><nav class="main-nav">
        <a class="main-nav-link" href="/">Time tracking</a>
        <a class="main-nav-link" data-nav="month" href="/ui/month">Overview</a>
        <a class="main-nav-link" data-nav="help" href="/ui/help">Help</a>
      </nav></header>
      <main id="main-content" tabindex="-1">
        <input type="search" class="admin-filter" />
        <table class="data-table" role="grid"><tbody><tr><td tabindex="0">Alpha</td></tr></tbody></table>
      </main>`
    // Anchor clicks would trigger jsdom navigation warnings; swallow them.
    for (const a of document.querySelectorAll('a')) {
      a.addEventListener('click', (e) => e.preventDefault())
    }
  }

  const press = (init: KeyboardEventInit) => handleShortcut(new KeyboardEvent('keydown', init))

  it('Alt+2 activates the second nav link', () => {
    setup()
    const overview = document.querySelector('a[data-nav="month"]') as HTMLAnchorElement
    const clicked = vi.fn()
    overview.addEventListener('click', clicked)
    press({ altKey: true, code: 'Digit2', key: '2' })
    expect(clicked).toHaveBeenCalled()
  })

  it('? activates the Help nav link', () => {
    setup()
    const help = document.querySelector('a[data-nav="help"]') as HTMLAnchorElement
    const clicked = vi.fn()
    help.addEventListener('click', clicked)
    press({ key: '?' })
    expect(clicked).toHaveBeenCalled()
  })

  it('"/" focuses the page search field', () => {
    setup()
    document.getElementById('main-content')?.focus()
    press({ key: '/' })
    expect(document.activeElement).toBe(document.querySelector('input[type="search"]'))
  })

  it('an arrow key enters the data grid when focus is still on the page', () => {
    setup()
    document.getElementById('main-content')?.focus()
    press({ key: 'ArrowDown' })
    expect((document.activeElement as HTMLElement).tagName).toBe('TD')
  })

  it('does not hijack "/" while typing in a field', () => {
    setup()
    const search = document.querySelector('input[type="search"]') as HTMLInputElement
    search.focus()
    const event = new KeyboardEvent('keydown', { key: '/', bubbles: true })
    const prevented = vi.spyOn(event, 'preventDefault')
    search.dispatchEvent(event)
    handleShortcut(event)
    expect(prevented).not.toHaveBeenCalled()
  })
})
