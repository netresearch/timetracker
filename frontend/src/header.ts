// Dynamics for the shared, server-rendered page header
// (templates/partials/header.html.twig): worktime sums and login-status
// polling against the same element IDs the ExtJS shell uses.
import { getJson } from './api/client'
import type { AppConfig } from './config'
import { setPaletteOpen } from './lib/commandPalette'
import { setShortcutsHelpOpen } from './lib/shortcutsHelp'

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

// Exported so the SolidJS worklog can refresh the header's today/week/month
// totals right after it saves, edits or deletes an entry — otherwise the header
// sums (loaded once on init) go stale until a full page reload (#446).
export async function updateWorktime(): Promise<void> {
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

    // Ctrl/⌘+K opens the command palette. A deliberate chord, so it fires even
    // while focus is in a field — it doesn't interfere with text entry.
    if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
      event.preventDefault()
      setPaletteOpen(true)

      return
    }

    // The Admin sub-nav owns its own arrow/Home/End keys (it moves focus down to
    // the search field itself). Bail out for those when the event ORIGINATED in
    // the sub-nav — keyed on event.target (immutable), not document.activeElement
    // (which the sub-nav already moved). This makes the partition independent of
    // listener order / stopImmediatePropagation: the global handler can never
    // re-handle the event after the sub-nav moved focus (e.g. bounce search→grid).
    // Global shortcuts (Alt+N, ?, /) are NOT arrow keys, so they still fire here.
    if (target !== null && target.closest('.admin-subnav') !== null
      && /^(Arrow(Up|Down|Left|Right)|Home|End)$/.test(event.key)) {
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
      // Open the keyboard-shortcuts cheat-sheet in place (a quick dismissible
      // overlay) rather than navigating away to the full /help page — that page
      // still lives under the header's Help link and the command palette.
      event.preventDefault()
      setShortcutsHelpOpen(true)

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
    // without a mouse click. ArrowDown enters the grid from the #main-content
    // landing region OR a bare <body> — focus drops to <body> when the user
    // clicks empty space, and from there an arrow key MUST still reach the grid
    // or keyboard navigation dead-ends after any click outside. It only fires
    // when a grid target actually exists, so grid-less pages keep native scroll;
    // the grid is also enterable from the search field via ArrowDown. (ArrowUp
    // re-entry stays #main-content-only — see below — so it never steals the
    // native scroll-up from <body>, which Tab already reaches the nav from.)
    const active = document.activeElement
    const atGridPivot = active === null || active === document.body
      || (active instanceof HTMLElement && active.id === 'main-content')

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

    // Tab / Shift+Tab from inside the open menu closes it and returns to the
    // "More" button — a known bar position from which normal Tab and arrow
    // roving continue — so the folded items are never a one-way Tab pocket.
    if (active instanceof HTMLElement && active.closest('.nav-more-menu') !== null
      && event.key === 'Tab') {
      const moreBtn = document.querySelector<HTMLElement>('.nav-more-btn')
      if (moreBtn !== null) {
        event.preventDefault()
        moreBtn.setAttribute('aria-expanded', 'false')
        const moreMenu = document.querySelector<HTMLElement>('.nav-more-menu')
        if (moreMenu !== null) {
          moreMenu.hidden = true
        }
        moreBtn.focus()
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
    // (ArrowDown) or from the search field (ArrowDown). Escape is intentionally
    // NOT a grid-entry key (it clears/leaves the filter in AdminCrudShell, the
    // conventional Escape behaviour). Only grids that advertise an arrow-exit
    // (data-arrow-nav) are arrow-enter targets — so entry and exit stay
    // symmetric and we never drop focus into a grid that only Tab can leave.
    const onPage = !inField && event.key === 'ArrowDown' && atGridPivot
    const fromSearch = active instanceof HTMLInputElement && active.type === 'search'
      && event.key === 'ArrowDown'
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

// ── Access-key overlay ───────────────────────────────────────────────────────
// Holding Alt reveals the keyboard shortcuts as small badges: 1..N on the nav
// bar items (Alt+1..N) and the modifier key on anything advertising an
// aria-keyshortcuts (Alt+A on Add, Alt+C on Continue, …). The page just sets a
// data-access-hint attribute + a body class; the badge itself is CSS.
const ACCESS_HINT_ATTR = 'data-access-hint'

/** "Alt+C" → "C", "Alt+1" → "1", "?" → "?"; the part after the last "+". */
function accessKeyOf(shortcut: string | null): string | null {
  const key = (shortcut ?? '').split('+').pop()?.trim() ?? ''

  return key === '' ? null : key.toUpperCase()
}

export function showAccessHints(): void {
  if (document.body.classList.contains('show-access-keys')) {
    return
  }
  // Nav bar items 1..7 only — that's the range the Alt+N handler binds
  // (/^Digit[1-7]$/), so a badge never advertises a shortcut that won't fire.
  navLinks().slice(0, 7).forEach((link, index) => link.setAttribute(ACCESS_HINT_ATTR, String(index + 1)))
  document.querySelectorAll<HTMLElement>('[aria-keyshortcuts]').forEach((el) => {
    const key = accessKeyOf(el.getAttribute('aria-keyshortcuts'))
    if (key !== null) {
      el.setAttribute(ACCESS_HINT_ATTR, key)
    }
  })
  document.body.classList.add('show-access-keys')
}

export function hideAccessHints(): void {
  if (!document.body.classList.contains('show-access-keys')) {
    return
  }
  document.querySelectorAll(`[${ACCESS_HINT_ATTR}]`).forEach((el) => el.removeAttribute(ACCESS_HINT_ATTR))
  document.body.classList.remove('show-access-keys')
}

/** The header "Help" affordance (icon + the "More" drawer link) opens the same
 *  shortcuts overlay as the `?` key, rather than navigating to the full /help
 *  page — so the `?` glyph means one thing whether typed or clicked. The link's
 *  href still points at /help, so it degrades gracefully if JS never loads. */
export function handleHelpClick(event: MouseEvent): void {
  // event.target can be a non-Element (document/window) in edge cases, and those
  // have no closest() — guard before calling it.
  const target = event.target
  if (target instanceof Element) {
    const help = target.closest<HTMLElement>('.app-header [data-nav="help"]')
    if (help) {
      event.preventDefault()
      setShortcutsHelpOpen(true)
    }
  }
}

function initShortcuts(): void {
  if (shortcutsWired) {
    return
  }
  shortcutsWired = true
  document.addEventListener('keydown', handleShortcut)
  // Delegated so it survives the priority-overflow re-parenting of nav links.
  // Capture phase so our preventDefault lands before the SolidJS Router's own
  // bubble-phase anchor handler — which otherwise client-navigates to /ui/help
  // (it skips a click once defaultPrevented).
  document.addEventListener('click', handleHelpClick, true)
  // Alt-hold shows the shortcut badges; Alt-up (or losing the window — so a
  // missed keyup can't strand them) hides them.
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Alt' && !event.repeat) {
      showAccessHints()
    }
  })
  document.addEventListener('keyup', (event) => {
    if (event.key === 'Alt') {
      hideAccessHints()
    }
  })
  window.addEventListener('blur', hideAccessHints)
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
  // Advertise the Alt+1–7 nav shortcuts to assistive tech, matching
  // handleShortcut's by-index mapping (so role-gated/absent items shift the
  // numbering identically). Only the SolidJS shell implements these, which is
  // exactly where this runs.
  navLinks().slice(0, 7).forEach((link, i) => {
    link.setAttribute('aria-keyshortcuts', `Alt+${i + 1}`)
  })
  void updateWorktime()
  setTimeout(() => {
    pollLoginStatus(config)
  }, STATUS_POLL_INTERVAL_MS)
}
