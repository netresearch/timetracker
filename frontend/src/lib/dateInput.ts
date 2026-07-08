import { dateFormat, fieldOrder, formatWith, isRealIsoDate, type DateFormatPref } from './dateFormat'

/**
 * Parse a partially-typed date for the worklog "type-the-day" autocomplete.
 *
 * The user types the least-significant fields first (day, then month, then
 * year), and any higher fields they leave off are filled from today. So on
 * 2026-07-08 typing just "7" means the 7th of the current month/year. Empty
 * input means "today".
 *
 * Reading direction is anchored on where DAY sits in the active display order:
 *   - DAY leftmost  (DE d.m.y): read the digits LEFT to RIGHT.
 *   - DAY rightmost (ISO y-m-d): read the digits RIGHT to LEFT.
 *   - DAY in the middle (en m/d/y): fall back to the RIGHT-to-LEFT reading
 *     (day = the last 2 digits). The shipping formats are ISO and DE, so this
 *     middle case is a best-effort fallback only.
 *
 * A 2-digit year expands to the current century (2000 + n) — no 19xx pivot.
 *
 * @returns canonical ISO yyyy-mm-dd, or null when the digits can't form a real
 *   calendar date. Empty input returns todayIso; a non-empty digit-less typo
 *   returns null (so the host flags it rather than defaulting to today).
 */
export function parseDateInput(raw: string, todayIso: string, pref: DateFormatPref = dateFormat()): string | null {
  // A truly empty field means "today" (the type-the-day convenience). A NON-empty
  // value with no digits (e.g. "x") is a typo, not a request for today — reject it
  // so callers' `?? raw` fall-through surfaces it to the host's own validation
  // instead of silently overwriting a real date with today.
  if (raw.trim() === '') {
    return todayIso
  }
  const digits = raw.replace(/\D/g, '')
  if (digits === '') {
    return null
  }

  // fieldOrder returns null for plain ISO (the default) — treat that as [y,m,d].
  const order = fieldOrder(pref) ?? ['y', 'm', 'd']
  const dayLeftmost = order.indexOf('d') === 0

  let dayStr: string
  let monthStr: string
  let yearStr: string
  if (dayLeftmost) {
    // LEFT to RIGHT: day is 1-2 digits (1 only when the total length is odd),
    // then month = next 2, then year = whatever remains.
    const dayLen = digits.length % 2 === 1 ? 1 : 2
    dayStr = digits.slice(0, dayLen)
    monthStr = digits.slice(dayLen, dayLen + 2)
    yearStr = digits.slice(dayLen + 2)
  } else {
    // RIGHT to LEFT: day = last 2 digits, month = the 2 before, year = the rest
    // at the front. Also the fallback for a middle-DAY order (e.g. en m/d/y).
    dayStr = digits.slice(-2)
    let rest = digits.slice(0, -2)
    monthStr = rest.slice(-2)
    rest = rest.slice(0, -2)
    yearStr = rest
  }

  // Missing higher fields come from today (dayStr is always non-empty here since digits !== '').
  const [todayYear, todayMonth] = todayIso.split('-')
  const month = monthStr === '' ? todayMonth! : monthStr
  let year: string
  if (yearStr === '') {
    year = todayYear!
  } else if (yearStr.length <= 2) {
    year = String(2000 + Number(yearStr)) // 2-digit (or 1-digit) year → current century
  } else {
    year = yearStr
  }
  if (!/^\d{4}$/.test(year)) {
    return null
  }

  const iso = `${year}-${month.padStart(2, '0')}-${dayStr.padStart(2, '0')}`

  return isRealIsoDate(iso) ? iso : null
}

/**
 * Render the grey ghost-preview text for a type-the-day input: the parsed date
 * formatted in the active display format, or '' when the input isn't a real date.
 */
export function previewDateInput(raw: string, todayIso: string, pref: DateFormatPref = dateFormat()): string {
  const iso = parseDateInput(raw, todayIso, pref)

  return iso === null ? '' : formatWith(iso, pref)
}

/**
 * The grey ghost-preview text to show beside a type-the-day input: the completed
 * date in the active format, but only while it differs from what's typed — an
 * empty field and a value already showing its final form both yield '' (no
 * redundant echo). Shared by DateField and the inline-grid date editor.
 */
export function dateGhost(raw: string, todayIso: string, pref: DateFormatPref = dateFormat()): string {
  const t = raw.trim()
  if (t === '') {
    return ''
  }
  const preview = previewDateInput(raw, todayIso, pref)

  return preview !== '' && preview !== raw ? preview : ''
}
