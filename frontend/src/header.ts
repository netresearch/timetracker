// Dynamics for the shared, server-rendered page header
// (templates/partials/header.html.twig): worktime sums and login-status
// polling against the same element IDs the ExtJS shell uses.
import { getJson } from './api/client'
import type { AppConfig } from './config'

interface TimeSummary {
  today: { duration: number }
  week: { duration: number }
  month: { duration: number }
}

const STATUS_POLL_INTERVAL_MS = 90_000

/** 'H:MM', optionally suffixed with person-days like the ExtJS header. */
export function formatDuration(minutes: number, inDays = false): string {
  const days = Math.floor((minutes / (60 * 8)) * 100) / 100
  const hours = Math.floor(minutes / 60)
  const mins = String(minutes % 60).padStart(2, '0')
  const text = `${hours}:${mins}`

  return inDays && days > 1 ? `${text} (${days} PT)` : text
}

/** Person-days only (e.g. '18.5 PT') — used for the Month badge. */
export function formatDays(minutes: number): string {
  const days = Math.floor((minutes / (60 * 8)) * 100) / 100

  return `${days} PT`
}

function setText(id: string, text: string): void {
  const element = document.getElementById(id)
  if (element !== null) {
    element.textContent = text
  }
}

function setBadge(loggedIn: boolean, userName: string): void {
  // Update every user badge (desktop header + mobile drawer share .js-user-badge).
  document.querySelectorAll('.js-user-badge').forEach((badge) => {
    badge.classList.toggle('status_active', loggedIn)
    badge.classList.toggle('status_inactive', !loggedIn)
    const name = badge.querySelector('.js-user-name')
    if (name !== null) {
      name.textContent = userName
    }
  })
}

async function updateWorktime(): Promise<void> {
  try {
    const summary = await getJson<TimeSummary>('/getTimeSummary')
    setText('worktime-day', formatDuration(summary.today.duration))
    setText('worktime-week', formatDuration(summary.week.duration))
    // Month shows person-days only; the hours stay in the title for reference.
    setText('worktime-month', formatDays(summary.month.duration))
    const month = document.getElementById('worktime-month')
    if (month !== null) {
      month.title = formatDuration(summary.month.duration)
    }
  } catch {
    // Header sums are non-critical; leave the rendered defaults.
  }
}

// The nav links are rendered in document order (and the overflow script keeps
// that order: it folds items from the end into the "More" menu, which itself is
// the last child of .main-nav). Role-gated items are simply absent. So a single
// query yields the n-th *available* item for Alt+N, with no key list to keep in
// sync with the Twig template.
function navLinks(): HTMLAnchorElement[] {
  return Array.from(document.querySelectorAll<HTMLAnchorElement>('.app-header .main-nav a.main-nav-link'))
}

/**
 * The menubar re-entry point: the active main-nav bar item, falling back to the
 * first bar item and finally the "More" button. Only *visible* bar items
 * (:not(.nav-menu-item)) qualify, so focus is never sent to a link folded into
 * the closed overflow menu. Used wherever a page hands focus back up to the nav.
 */
export function activeNavLink(): HTMLElement | null {
  return document.querySelector<HTMLElement>('.app-header .main-nav .main-nav-link[aria-current="page"]:not(.nav-menu-item)')
    ?? document.querySelector<HTMLElement>('.app-header .main-nav .main-nav-link:not(.nav-menu-item)')
    ?? document.querySelector<HTMLElement>('.app-header .main-nav .nav-more-btn')
}

let shortcutsWired = false

/**
 * Keyboard shortcuts for the SolidJS shell (documented on the Help page):
 * Alt+1–7 switches to the n-th nav item, and `?` opens Help. The grid-specific
 * shortcuts (Alt+A/C/D/…) still live in the ExtJS tracking shell. Clicking the
 * nav link reuses the router's anchor interception and the role gating.
 */
export function handleShortcut(event: KeyboardEvent): void {
  {
    const target = event.target as HTMLElement | null
    const inField = target !== null
      && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)

    // While a modal dialog is open (Ark UI sets role="dialog" + data-state),
    // stand down entirely: its own focus trap owns the keyboard. Otherwise the
    // modifier shortcuts (Alt+A would re-open/clobber the form, Alt+1–7 would
    // navigate away without dismissing the dialog) reach controls behind it.
    if (document.querySelector('[role="dialog"][data-state="open"]') !== null) {
      return
    }

    if (event.altKey && !event.ctrlKey && !event.metaKey && /^Digit[1-7]$/.test(event.code)) {
      const link = navLinks()[Number(event.code.slice(5)) - 1]
      if (link !== undefined) {
        event.preventDefault()
        link.click()
      }

      return
    }

    // Alt+A → add a new entry on pages that offer one (the Add button is tagged
    // with data-keyboard-add), mirroring the ExtJS tracking grid's Alt+A.
    if (event.altKey && !event.ctrlKey && !event.metaKey && event.code === 'KeyA') {
      const add = document.querySelector<HTMLElement>('#main-content [data-keyboard-add]')
      if (add !== null) {
        event.preventDefault()
        add.click()
      }

      return
    }

    // Single-character shortcut: only outside fields and without modifiers, so it
    // can't fire while typing text (WCAG 2.1.4 mitigation).
    if (event.key === '?' && !inField && !event.altKey && !event.ctrlKey && !event.metaKey) {
      const help = document.querySelector<HTMLAnchorElement>('.app-header .main-nav a[data-nav="help"]')
      if (help !== null) {
        event.preventDefault()
        help.click()
      }

      return
    }

    // '/' jumps to the page's search/filter field (search mode).
    if (event.key === '/' && !inField && !event.altKey && !event.ctrlKey && !event.metaKey) {
      const search = document.querySelector<HTMLElement>('#main-content input[type="search"]')
      if (search !== null) {
        event.preventDefault()
        search.focus()
      }

      return
    }

    // Move focus into the first data grid so table keyboard navigation works
    // without a mouse click — but only from the route-change focus state (the
    // #main-content region itself, not document.body), so we never hijack page
    // scrolling once the user has interacted. Also enterable from the search
    // field via ArrowDown or Escape (back to the table).
    const active = document.activeElement

    // "More" overflow as a WAI-ARIA menu button: ArrowDown/ArrowUp on the button
    // opens the disclosure and moves focus to the first/last item (rather than the
    // bar item's usual "descend into page content"). Escape closes and returns to
    // the button — handled framework-neutrally in header-behavior.html.twig.
    if (active instanceof HTMLElement && active.matches('.nav-more-btn')
      && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      event.preventDefault()
      if (active.getAttribute('aria-expanded') !== 'true') {
        active.click() // the behavior script's click handler opens + unhides the menu
      }
      const items = Array.from(document.querySelectorAll<HTMLElement>('.nav-more-menu .main-nav-link'))
      if (event.key === 'ArrowDown') {
        items[0]?.focus()
      } else {
        items[items.length - 1]?.focus()
      }

      return
    }

    // Inside the open "More" menu: ArrowUp/Down (and Home/End) rove its items,
    // wrapping around like a vertical menu. Left/Right stay native (no-op).
    if (active instanceof HTMLElement && active.closest('.nav-more-menu') !== null
      && /^(ArrowDown|ArrowUp|Home|End)$/.test(event.key)) {
      event.preventDefault()
      const items = Array.from(document.querySelectorAll<HTMLElement>('.nav-more-menu .main-nav-link'))
      const j = items.indexOf(active)
      if (event.key === 'ArrowDown') {
        items[(j + 1) % items.length]?.focus()
      } else if (event.key === 'ArrowUp') {
        items[(j - 1 + items.length) % items.length]?.focus()
      } else if (event.key === 'Home') {
        items[0]?.focus()
      } else {
        items[items.length - 1]?.focus()
      }

      return
    }

    // Main navigation behaves as a horizontal menubar: Left/Right/Home/End rove
    // between the visible bar items, ArrowDown drops into the page content
    // (sub-nav → search → grid → first focusable). Enter/Space still activates.
    // Folded links live inside the (open) "More" menu — they keep .main-nav-link
    // but must NOT rove the bar (they'd have no index in the bar set and snap
    // focus to the first bar link). Exclude anything inside .nav-more-menu so
    // arrows on a folded link fall through to the menu's native Tab order.
    const navItem = active instanceof HTMLElement
      && active.closest('.app-header .main-nav') !== null
      && active.closest('.nav-more-menu') === null
      && (active.matches('.main-nav-link') || active.matches('.nav-more-btn'))
      ? active
      : null
    if (navItem !== null && /^(ArrowRight|ArrowLeft|ArrowDown|Home|End)$/.test(event.key)) {
      event.preventDefault()
      // Bar items only: the priority-overflow script tags folded links with
      // .nav-menu-item and moves them into the (hidden) "More" menu, so
      // :not(.nav-menu-item) leaves exactly what's on the bar. The "More" button
      // joins the roving order only once its wrapper is shown (something folded).
      // This is structural, not geometric, so it never depends on layout being
      // measured (offsetParent is unreliable mid-resize and absent in tests).
      const items = Array.from(
        document.querySelectorAll<HTMLElement>(
          '.app-header .main-nav .main-nav-link:not(.nav-menu-item), .app-header .main-nav .nav-more-btn',
        ),
      ).filter((el) => {
        const more = el.closest<HTMLElement>('.nav-more')

        return more === null || !more.hidden
      })
      const i = items.indexOf(navItem)
      if (event.key === 'ArrowRight') {
        items[Math.min(items.length - 1, i + 1)]?.focus()
      } else if (event.key === 'ArrowLeft') {
        items[Math.max(0, i - 1)]?.focus()
      } else if (event.key === 'Home') {
        items[0]?.focus()
      } else if (event.key === 'End') {
        items[items.length - 1]?.focus()
      } else {
        // ArrowDown descends only into a real arrow-navigable target — the
        // active sub-nav, the search field, or an arrow-exitable grid — each of
        // which has an ArrowUp path back to the nav. We deliberately do NOT fall
        // back to a generic focusable: descending onto, say, a filter button on
        // a grid-less page (Auswertung/Billing/…) would be a one-way arrow trip
        // (no ArrowUp home). On those pages ArrowDown is a no-op (Tab enters the
        // content instead); the menubar stays put rather than stranding focus.
        const target = document.querySelector<HTMLElement>('.admin-subnav-link[aria-current="page"]')
          ?? document.querySelector<HTMLElement>('.admin-subnav-link')
          ?? document.querySelector<HTMLElement>('#main-content input[type="search"]')
          ?? document.querySelector<HTMLElement>('#main-content .data-table[role="grid"][data-arrow-nav] [tabindex="0"]')
        target?.focus()
      }

      return
    }

    // #main-content is where focus lands on initial load and on every route
    // change — it acts as a keyboard PIVOT. ArrowUp re-enters the main-nav
    // menubar so EVERY page can climb back to the nav (not just Admin via its
    // sub-nav); without it, activating a nav item stranded focus on grid-less
    // pages (Billing/Extras/Month/…). ArrowDown drops into the page's grid.
    if (!inField && event.key === 'ArrowUp'
      && active instanceof HTMLElement && active.id === 'main-content') {
      const nav = activeNavLink()
      if (nav !== null) {
        event.preventDefault()
        nav.focus()
      }

      return
    }

    // Drop focus into the page's data grid — from the #main-content landing spot
    // (ArrowDown) or from the search field (ArrowDown/Escape, back to the table).
    // Only grids that advertise an arrow-exit (data-arrow-nav) are arrow-enter
    // targets — so entry and exit stay symmetric and we never drop focus into a
    // grid that can only be left with Tab (e.g. the read-only Auswertung table).
    const onPage = !inField && event.key === 'ArrowDown'
      && active instanceof HTMLElement && active.id === 'main-content'
    const fromSearch = active instanceof HTMLInputElement && active.type === 'search'
      && (event.key === 'ArrowDown' || event.key === 'Escape')
    if (onPage || fromSearch) {
      const grid = document.querySelector<HTMLElement>('#main-content .data-table[role="grid"][data-arrow-nav]')
      const cell = grid?.querySelector<HTMLElement>('[tabindex="0"]') ?? grid?.querySelector<HTMLElement>('th, td')
      if (cell) {
        event.preventDefault()
        cell.focus()
      }
    }
  }
}

function initShortcuts(): void {
  if (shortcutsWired) {
    return
  }
  shortcutsWired = true
  document.addEventListener('keydown', handleShortcut)
}

function pollLoginStatus(config: AppConfig): void {
  void getJson<{ loginStatus: boolean }>('/status/check')
    .then((status) => {
      setBadge(status.loginStatus, status.loginStatus ? config.userName : '')
    })
    .catch(() => {
      setBadge(false, '')
    })
    .finally(() => {
      setTimeout(() => {
        pollLoginStatus(config)
      }, STATUS_POLL_INTERVAL_MS)
    })
}

export function initHeaderDynamics(config: AppConfig): void {
  setBadge(true, config.userName)
  initShortcuts()
  void updateWorktime()
  setTimeout(() => {
    pollLoginStatus(config)
  }, STATUS_POLL_INTERVAL_MS)
}
