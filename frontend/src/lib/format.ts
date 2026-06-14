export function pad2(value: number): string {
  return String(value).padStart(2, '0')
}

/** Local calendar date as 'YYYY-MM-DD' (no timezone shift, unlike toISOString). */
export function isoDate(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
}

/** Formats minutes as '[+|-]HH:MM'; the sign is shown for negatives always, for positives on request. */
export function formatMinutes(minutes: number, showSign = false): string {
  const absolute = Math.abs(Math.trunc(minutes))
  const hours = Math.floor(absolute / 60)
  const mins = absolute - hours * 60
  const sign = minutes < 0 ? '-' : showSign && minutes > 0 ? '+' : ''

  return `${sign}${pad2(hours)}:${pad2(mins)}`
}

/** 'June 2026' / 'Juni 2026'. */
export function formatMonthTitle(date: Date, locale: string): string {
  return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(date)
}

/** 'Jun 9, 2026' / '9. Juni 2026' (medium date). */
export function formatDay(date: Date, locale: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(date)
}
