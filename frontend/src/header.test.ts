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
        <div class="nav-more" hidden>
          <button type="button" class="nav-more-btn">More</button>
          <div class="nav-more-menu" hidden></div>
        </div>
      </nav></header>
      <main id="main-content" tabindex="-1">
        <button type="button" data-keyboard-add>Add</button>
        <input type="search" class="admin-filter" />
        <table class="data-table" role="grid" data-arrow-nav><tbody><tr><td tabindex="0">Alpha</td></tr></tbody></table>
      </main>`
    // Anchor clicks would trigger jsdom navigation warnings; swallow them.
    for (const a of document.querySelectorAll('a')) {
      a.addEventListener('click', (e) => e.preventDefault())
    }
  }

  /** Mimic the priority-overflow script folding the last `count` bar links into
   *  the (open) "More" menu, in priority order, and marking the button expanded. */
  function foldNavLinks(count = 1): void {
    const links = Array.from(document.querySelectorAll<HTMLElement>('.main-nav-link'))
    const menu = document.querySelector('.nav-more-menu')!
    for (const link of links.slice(-count)) {
      link.classList.add('nav-menu-item')
      menu.appendChild(link) // preserves priority order (slice keeps DOM order)
    }
    document.querySelector('.nav-more')!.removeAttribute('hidden')
    menu.removeAttribute('hidden')
    document.querySelector('.nav-more-btn')!.setAttribute('aria-expanded', 'true')
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

  it('Alt+A clicks the page add button', () => {
    setup()
    const add = document.querySelector('[data-keyboard-add]') as HTMLButtonElement
    const clicked = vi.fn()
    add.addEventListener('click', clicked)
    press({ altKey: true, code: 'KeyA', key: 'a' })
    expect(clicked).toHaveBeenCalled()
  })

  it('"/" focuses the page search field', () => {
    setup()
    document.getElementById('main-content')?.focus()
    press({ key: '/' })
    expect(document.activeElement).toBe(document.querySelector('input[type="search"]'))
  })

  it('ArrowDown enters the data grid when focus is still on the page', () => {
    setup()
    document.getElementById('main-content')?.focus()
    press({ key: 'ArrowDown' })
    expect((document.activeElement as HTMLElement).tagName).toBe('TD')
  })

  it('ArrowUp from #main-content re-enters the main-nav menubar (every page)', () => {
    setup()
    const active = document.querySelector('a[data-nav="month"]') as HTMLAnchorElement
    active.setAttribute('aria-current', 'page') // the route's active nav item
    document.getElementById('main-content')?.focus()
    press({ key: 'ArrowUp' })
    expect(document.activeElement).toBe(active)
  })

  // Clicking empty space drops focus to <body>; an arrow key must still
  // (re-)enter the page so keyboard navigation never dead-ends after a click out.
  it('ArrowDown with focus on <body> enters the data grid', () => {
    setup()
    document.body.focus()
    expect(document.activeElement).toBe(document.body)
    press({ key: 'ArrowDown' })
    expect((document.activeElement as HTMLElement).tagName).toBe('TD')
  })

  it('ArrowUp with focus on <body> re-enters the main-nav', () => {
    setup()
    const active = document.querySelector('a[data-nav="month"]') as HTMLAnchorElement
    active.setAttribute('aria-current', 'page')
    document.body.focus()
    press({ key: 'ArrowUp' })
    expect(document.activeElement).toBe(active)
  })

  it('ArrowDown on <body> with no grid stays put (native scroll preserved)', () => {
    setup()
    document.getElementById('main-content')!.innerHTML = '<p>No grid here</p>'
    document.body.focus()
    const event = new KeyboardEvent('keydown', { key: 'ArrowDown', cancelable: true })
    handleShortcut(event)
    expect(document.activeElement).toBe(document.body) // no target → no jump
    expect(event.defaultPrevented).toBe(false) // page can still scroll
  })

  it('ArrowDown from the search field enters the table (Escape does not)', () => {
    setup()
    const search = document.querySelector('input[type="search"]') as HTMLInputElement
    search.focus()
    press({ key: 'ArrowDown' })
    expect((document.activeElement as HTMLElement).tagName).toBe('TD')

    // Escape is no longer a grid-entry key in the global handler — it clears/
    // leaves the filter (AdminCrudShell). The global handler must leave it alone.
    search.focus()
    press({ key: 'Escape' })
    expect(document.activeElement).toBe(search)
  })

  it('does not arrow-enter a grid that lacks an arrow-exit (Tab-only)', () => {
    setup()
    // A read-only grid (no data-arrow-nav) must not be an arrow-entry target,
    // so focus is never dropped where only Tab can leave.
    document.querySelector('.data-table')!.removeAttribute('data-arrow-nav')
    const search = document.querySelector('input[type="search"]') as HTMLInputElement
    search.focus()
    press({ key: 'ArrowDown' })
    expect(document.activeElement).toBe(search) // stays put; no jump into the grid
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

  it('the main nav roves horizontally like a menubar', () => {
    setup()
    const links = document.querySelectorAll<HTMLAnchorElement>('.main-nav-link')
    links[0]!.focus()
    press({ key: 'ArrowRight' })
    expect(document.activeElement).toBe(links[1])
    press({ key: 'ArrowRight' })
    expect(document.activeElement).toBe(links[2])
    press({ key: 'ArrowRight' }) // clamps at the last item
    expect(document.activeElement).toBe(links[2])
    press({ key: 'ArrowLeft' })
    expect(document.activeElement).toBe(links[1])
    press({ key: 'Home' })
    expect(document.activeElement).toBe(links[0])
    press({ key: 'End' })
    expect(document.activeElement).toBe(links[2])
  })

  it('ArrowDown from a nav link drops focus into the page content', () => {
    setup()
    const first = document.querySelector('.main-nav-link') as HTMLAnchorElement
    first.focus()
    press({ key: 'ArrowDown' })
    expect(document.activeElement).toBe(document.querySelector('input[type="search"]'))
  })

  it('ArrowDown from a nav link is a no-op on grid-less pages (no one-way descent)', () => {
    setup()
    // A page with no sub-nav, no search and no arrow-nav grid (e.g. Billing):
    // ArrowDown must NOT strand focus on a generic control it cannot arrow back from.
    document.getElementById('main-content')!.innerHTML = '<button type="button">Pay</button>'
    const first = document.querySelector('.main-nav-link') as HTMLAnchorElement
    first.focus()
    press({ key: 'ArrowDown' })
    expect(document.activeElement).toBe(first) // stays in the menubar
  })

  it('ArrowDown from a nav link prefers the active sub-nav when present', () => {
    setup()
    const content = document.getElementById('main-content')!
    content.insertAdjacentHTML('afterbegin',
      '<nav><button class="admin-subnav-link">Users</button>'
      + '<button class="admin-subnav-link" aria-current="page">Teams</button></nav>')
    const first = document.querySelector('.main-nav-link') as HTMLAnchorElement
    first.focus()
    press({ key: 'ArrowDown' })
    expect(document.activeElement).toBe(document.querySelector('.admin-subnav-link[aria-current="page"]'))
  })

  it('skips a folded nav item and includes "More" once it is shown', () => {
    setup()
    foldNavLinks() // Help folds into the now-visible "More" menu
    const links = document.querySelectorAll<HTMLAnchorElement>('.main-nav-link')
    const more = document.querySelector('.nav-more-btn') as HTMLButtonElement
    links[0]!.focus()
    press({ key: 'End' }) // last *bar* stop is the More button, not the folded link
    expect(document.activeElement).toBe(more)
    press({ key: 'ArrowLeft' }) // back onto the last visible bar link (Overview)
    expect(document.activeElement).toBe(links[1])
  })

  it('ArrowDown/ArrowUp on the "More" button moves focus into the menu', () => {
    setup()
    foldNavLinks(2) // Overview + Help fold into the menu
    const items = document.querySelectorAll<HTMLAnchorElement>('.nav-more-menu .main-nav-link')
    const more = document.querySelector('.nav-more-btn') as HTMLButtonElement
    more.focus()
    press({ key: 'ArrowDown' })
    expect(document.activeElement).toBe(items[0]) // first menu item
    more.focus()
    press({ key: 'ArrowUp' })
    expect(document.activeElement).toBe(items[items.length - 1]) // last menu item
  })

  it('arrows rove the open "More" menu items (wrapping), Left/Right stay native', () => {
    setup()
    foldNavLinks(2)
    const items = document.querySelectorAll<HTMLAnchorElement>('.nav-more-menu .main-nav-link')
    items[0]!.focus()
    press({ key: 'ArrowDown' })
    expect(document.activeElement).toBe(items[1])
    press({ key: 'ArrowDown' }) // wraps to first
    expect(document.activeElement).toBe(items[0])
    press({ key: 'ArrowUp' }) // wraps to last
    expect(document.activeElement).toBe(items[1])
    press({ key: 'End' })
    expect(document.activeElement).toBe(items[items.length - 1])
    press({ key: 'Home' })
    expect(document.activeElement).toBe(items[0])
    press({ key: 'ArrowRight' }) // not roved by the menu; focus stays put
    expect(document.activeElement).toBe(items[0])
  })

  it('Tab from inside the open "More" menu closes it and returns to the button', () => {
    setup()
    foldNavLinks(2)
    const more = document.querySelector('.nav-more-btn') as HTMLButtonElement
    const item = document.querySelector('.nav-more-menu .main-nav-link') as HTMLAnchorElement
    item.focus()
    press({ key: 'Tab' }) // must not Tab item-by-item out to the worktime badges
    expect(document.activeElement).toBe(more)
    expect(more.getAttribute('aria-expanded')).toBe('false')
    expect((document.querySelector('.nav-more-menu') as HTMLElement).hidden).toBe(true)
  })

  it('stands down while a modal dialog is open', () => {
    setup()
    document.body.insertAdjacentHTML('beforeend', '<div role="dialog" data-state="open"></div>')
    const add = document.querySelector('[data-keyboard-add]') as HTMLButtonElement
    const clicked = vi.fn()
    add.addEventListener('click', clicked)
    press({ altKey: true, code: 'KeyA', key: 'a' }) // Alt+A must not reach the page
    expect(clicked).not.toHaveBeenCalled()

    const overview = document.querySelector('a[data-nav="month"]') as HTMLAnchorElement
    const nav = vi.fn()
    overview.addEventListener('click', nav)
    press({ altKey: true, code: 'Digit2', key: '2' }) // Alt+2 must not navigate
    expect(nav).not.toHaveBeenCalled()
  })

  // Integration: the global handler is on document; a real bubbling ArrowDown
  // from the sub-nav must NOT bounce past the search into the grid — even with
  // NO stopImmediatePropagation in the sub-nav handler (the partition must not
  // depend on event-delegation order). Guards against a future on:keydown refactor.
  it('does not bounce a sub-nav-sourced ArrowDown past the search into the grid', () => {
    setup()
    const content = document.getElementById('main-content')!
    content.insertAdjacentHTML('afterbegin',
      '<nav class="admin-subnav"><button class="admin-subnav-link" aria-current="page">Users</button></nav>')
    const subnavBtn = document.querySelector('.admin-subnav-link') as HTMLButtonElement
    const search = document.querySelector('input[type="search"]') as HTMLInputElement
    // mimic Admin.tsx's ArrowDown — but deliberately WITHOUT stopImmediatePropagation
    subnavBtn.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); search.focus() }
    })
    document.addEventListener('keydown', handleShortcut)
    try {
      subnavBtn.focus()
      subnavBtn.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
      expect(document.activeElement).toBe(search) // landed on search, not a grid TD
    } finally {
      document.removeEventListener('keydown', handleShortcut)
    }
  })
})
