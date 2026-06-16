/**
 * Parse a terse time entry into 24-hour "H:i", mirroring the legacy ExtJS
 * work-log grid's flexible altFormats (g:ia | gi | Gi | H:i | ga | …) so users
 * can type times tersely. Returns null when the input can't be parsed.
 *
 * Accepts e.g. 9:30, 09:30, 9.30, 9h30, 930, 0930, 9, 9:30am, 9:30a, 930p, 9p,
 * 12a (→ 00:00), 12p (→ 12:00).
 */
export function parseTime(input: string): string | null {
  const raw = input.trim().toLowerCase()
  if (raw === '') {
    return null
  }

  // Optional am/pm suffix (a, p, am, pm, with optional dots/space).
  let meridiem: 'a' | 'p' | null = null
  let body = raw
  const suffix = /\s*([ap])\.?m?\.?$/.exec(body)
  if (suffix !== null) {
    meridiem = suffix[1] as 'a' | 'p'
    body = body.slice(0, suffix.index).trim()
  }

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

/** ExtJS list rows carry the date as d/m/Y; the HTML date input needs Y-m-d. */
export function toIsoDate(displayDate: string): string {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(displayDate.trim())

  return match !== null ? `${match[3]}-${match[2]}-${match[1]}` : ''
}
