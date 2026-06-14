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

// Nav order matching the shared header markup; role-gated items that aren't
// rendered are simply skipped, so Alt+N maps to the n-th *available* item.
const NAV_KEYS = ['tracking', 'month', 'auswertung', 'extras', 'billing', 'admin', 'settings', 'help']

function navLinks(): HTMLAnchorElement[] {
  return NAV_KEYS.map((key) =>
    key === 'tracking'
      ? document.querySelector<HTMLAnchorElement>('.app-header .main-nav a.main-nav-link:not([data-nav])')
      : document.querySelector<HTMLAnchorElement>(`.app-header .main-nav a.main-nav-link[data-nav="${key}"]`),
  ).filter((link): link is HTMLAnchorElement => link !== null)
}

let shortcutsWired = false

/**
 * Keyboard shortcuts for the SolidJS shell (documented on the Help page):
 * Alt+1–7 switches to the n-th nav item, and `?` opens Help. The grid-specific
 * shortcuts (Alt+A/C/D/…) still live in the ExtJS tracking shell. Clicking the
 * nav link reuses the router's anchor interception and the role gating.
 */
function initShortcuts(): void {
  if (shortcutsWired) {
    return
  }
  shortcutsWired = true

  document.addEventListener('keydown', (event) => {
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

    if (event.key === '?' && !inField) {
      const help = document.querySelector<HTMLAnchorElement>('.app-header .main-nav a[data-nav="help"]')
      if (help !== null) {
        event.preventDefault()
        help.click()
      }
    }
  })
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
