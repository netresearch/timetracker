/**
 * Parse a terse time entry into 24-hour "H:i", mirroring the legacy ExtJS
 * work-log grid's flexible altFormats (g:ia | gi | Gi | H:i | ga | …) so users
 * can type times tersely. Returns null when the input can't be parsed.
 *
 * Accepts e.g. 9:30, 09:30, 9.30, 9h30, 930, 0930, 9, 9:30am, 9:30a, 930p, 9p,
 * 12a (→ 00:00), 12p (→ 12:00).
 */
export function parseTime(input: string): string | null {
  let body = input.trim().toLowerCase()
  if (body === '') {
    return null
  }

  // Optional am/pm suffix — string ops (not a regex) to avoid backtracking.
  let meridiem: 'a' | 'p' | null = null
  if (body.endsWith('am')) {
    meridiem = 'a'
    body = body.slice(0, -2)
  } else if (body.endsWith('pm')) {
    meridiem = 'p'
    body = body.slice(0, -2)
  } else if (body.endsWith('a')) {
    meridiem = 'a'
    body = body.slice(0, -1)
  } else if (body.endsWith('p')) {
    meridiem = 'p'
    body = body.slice(0, -1)
  }
  body = body.trim()

  let hours: number
  let minutes: number
  const separated = /^(\d{1,2})[:.h](\d{2})$/.exec(body) // 9:30, 9.30, 9h30
  if (separated !== null) {
    hours = Number(separated[1])
    minutes = Number(separated[2])
  } else if (/^\d{1,2}$/.test(body)) { // 9, 09
    hours = Number(body)
    minutes = 0
  } else if (/^\d{3}$/.test(body)) { // 930
    hours = Number(body.slice(0, 1))
    minutes = Number(body.slice(1))
  } else if (/^\d{4}$/.test(body)) { // 0930
    hours = Number(body.slice(0, 2))
    minutes = Number(body.slice(2))
  } else {
    return null
  }

  if (meridiem === 'p' && hours < 12) {
    hours += 12
  } else if (meridiem === 'a' && hours === 12) {
    hours = 0
  }

  if (hours > 23 || minutes > 59) {
    return null
  }

  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
}

/**
 * Convert a d/m/Y date string (the ExtJS list-row format) to Y-m-d (ISO), or
 * null when it doesn't match. Shared by toIsoDate, the grid's displayDate, and
 * the entry sort key so the one regex lives in a single place.
 */
export function dmyToIso(value: string): string | null {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value.trim())

  return match !== null ? `${match[3]}-${match[2]}-${match[1]}` : null
}

/** ExtJS list rows carry the date as d/m/Y; the HTML date input needs Y-m-d. */
export function toIsoDate(displayDate: string): string {
  return dmyToIso(displayDate) ?? ''
}
