import { beforeEach, describe, expect, it } from 'vitest'

import { syncNav } from './nav'

// Mirrors the server-rendered header markup (templates/partials/header.html.twig):
// main-bar links, the Settings/Help icon actions, the mobile drawer links, and a
// worktime badge that also carries data-nav="month" but must never be marked active.
function renderHeader(): void {
  document.body.innerHTML = `
    <nav class="main-nav">
      <a class="main-nav-link" data-nav="tracking" href="/ui/tracking">Worklog</a>
      <a class="main-nav-link" data-nav="month" href="/ui/month">Overview</a>
      <a class="main-nav-link" data-nav="auswertung" href="/ui/auswertung">Evaluation</a>
    </nav>
    <a id="worktime-day" data-nav="month" href="/ui/month?days=today">0:00</a>
    <a class="header-icon-link" data-nav="settings" href="/ui/settings">Settings</a>
    <a class="header-icon-link" data-nav="help" href="/ui/help">Help</a>
    <div class="mobile-drawer">
      <a class="drawer-link" data-nav="tracking" href="/ui/tracking">Worklog</a>
      <a class="drawer-link" data-nav="month" href="/ui/month">Overview</a>
      <a class="drawer-link" data-nav="settings" href="/ui/settings">Settings</a>
    </div>
  `
}

function activeNavs(selector: string): string[] {
  return [...document.querySelectorAll<HTMLAnchorElement>(selector)]
    .filter((link) => link.classList.contains('is-active'))
    .map((link) => link.dataset.nav ?? '')
}

describe('syncNav', () => {
  beforeEach(renderHeader)

  it('marks the route segment active across bar, icon and drawer links', () => {
    syncNav('/ui/tracking')

    expect(activeNavs('.main-nav-link')).toEqual(['tracking'])
    expect(activeNavs('.drawer-link')).toEqual(['tracking'])
    expect(activeNavs('.header-icon-link')).toEqual([])
    const active = document.querySelector('.main-nav-link[data-nav="tracking"]')
    expect(active?.getAttribute('aria-current')).toBe('page')
  })

  it('moves the marking (and aria-current) when the route changes', () => {
    syncNav('/ui/tracking')
    syncNav('/ui/settings')

    expect(activeNavs('.main-nav-link')).toEqual([])
    expect(activeNavs('.header-icon-link')).toEqual(['settings'])
    expect(activeNavs('.drawer-link')).toEqual(['settings'])
    const previous = document.querySelector('.main-nav-link[data-nav="tracking"]')
    expect(previous?.hasAttribute('aria-current')).toBe(false)
  })

  it('defaults to month for the SPA root and skips the worktime badge', () => {
    syncNav('/ui/')

    expect(activeNavs('.main-nav-link')).toEqual(['month'])
    expect(activeNavs('.drawer-link')).toEqual(['month'])
    // The worktime badge carries data-nav="month" but is not a nav link.
    expect(document.getElementById('worktime-day')?.classList.contains('is-active')).toBe(false)
  })
})
