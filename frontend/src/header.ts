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

    if (event.key === '?' && !inField) {
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
    // without a mouse click: with an arrow key while focus is still on the page
    // (e.g. right after a route change, where #main-content holds focus), and
    // from the search field via ArrowDown or Escape (back to the table).
    const active = document.activeElement
    const onPage = !inField && /^Arrow(Up|Down|Left|Right)$/.test(event.key)
      && (active === document.body || (active instanceof HTMLElement && active.id === 'main-content'))
    const fromSearch = active instanceof HTMLInputElement && active.type === 'search'
      && (event.key === 'ArrowDown' || event.key === 'Escape')
    if (onPage || fromSearch) {
      const grid = document.querySelector<HTMLElement>('#main-content .data-table[role="grid"]')
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
