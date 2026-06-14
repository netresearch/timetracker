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
  const badge = document.getElementById('user-badge')
  if (badge === null) {
    return
  }

  badge.classList.toggle('status_active', loggedIn)
  badge.classList.toggle('status_inactive', !loggedIn)
  const name = badge.querySelector('.user-name')
  if (name !== null) {
    name.textContent = userName
  }
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
  void updateWorktime()
  setTimeout(() => {
    pollLoginStatus(config)
  }, STATUS_POLL_INTERVAL_MS)
}
