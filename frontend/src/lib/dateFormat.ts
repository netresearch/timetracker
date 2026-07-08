import { createSignal } from 'solid-js'

import { appConfig } from '../config'

/**
 * User preference for how dates render in the UI. Client-side only (localStorage),
 * like the grid Enter-behavior and theme — it carries no data semantics, so it
 * never touches the server settings. The WIRE format, the sort key, and the inline
 * date EDITOR stays ISO yyyy-mm-dd. Display formatting applies at the read-only
 * grid leaves; modal FORM date fields additionally round-trip through
 * parseUserDate() below, which accepts the active display format OR ISO and
 * always validates back to ISO for the save path. Signal-backed so open grids
 * reformat live.
 */
export type DateFormatMode = 'iso' | 'auto' | 'custom'

export interface DateFormatPref {
  mode: DateFormatMode
  /** Custom token pattern (only used when mode === 'custom'). */
  pattern: string
}

const STORAGE_KEY = 'tt-date-format'
export const DEFAULT_PREF: DateFormatPref = { mode: 'iso', pattern: 'DD.MM.YYYY' }
const ISO_SHAPE = /^\d{4}-\d{2}-\d{2}$/

type Token = { lit: string } | { field: 'y' | 'm' | 'd'; width: 1 | 2 | 4 }

// Canonical digit-only tokens + PHP-style %-aliases, matched longest-source-first
// so YYYY beats YY and MM beats M. Month/weekday NAMES are intentionally absent —
// they are locale-bound (that is what 'auto' is for) and not round-trippable.
const TOKEN_TABLE: { src: string; tok: Token }[] = [
  { src: 'YYYY', tok: { field: 'y', width: 4 } },
  { src: '%Y', tok: { field: 'y', width: 4 } },
  { src: 'YY', tok: { field: 'y', width: 2 } },
  { src: '%y', tok: { field: 'y', width: 2 } },
  { src: 'MM', tok: { field: 'm', width: 2 } },
  { src: '%m', tok: { field: 'm', width: 2 } },
  { src: 'DD', tok: { field: 'd', width: 2 } },
  { src: '%d', tok: { field: 'd', width: 2 } },
  { src: '%D', tok: { field: 'd', width: 2 } },
  { src: 'M', tok: { field: 'm', width: 1 } },
  { src: 'D', tok: { field: 'd', width: 1 } },
]
const MAX_PATTERN_LEN = 32

// Single left-to-right scan, no regex over the user pattern → linear time, no
// ReDoS. Any char not starting a known token is emitted as a literal.
function tokenize(pattern: string): Token[] {
  const out: Token[] = []
  let i = 0
  while (i < pattern.length) {
    const hit = TOKEN_TABLE.find((entry) => pattern.startsWith(entry.src, i))
    if (hit !== undefined) {
      out.push(hit.tok)
      i += hit.src.length
    } else {
      out.push({ lit: pattern[i]! })
      i += 1
    }
  }

  return out
}

export function validatePattern(raw: string): { ok: true } | { ok: false; reason: 'empty' | 'too-long' | 'no-date-token' } {
  const pattern = raw.trim()
  if (pattern === '') {
    return { ok: false, reason: 'empty' }
  }
  if (pattern.length > MAX_PATTERN_LEN) {
    return { ok: false, reason: 'too-long' }
  }
  if (!tokenize(pattern).some((token) => 'field' in token)) {
    return { ok: false, reason: 'no-date-token' } // e.g. "hello" — would blank every date
  }

  return { ok: true }
}

function applyTokens(iso: string, tokens: Token[]): string {
  const [year, month, day] = iso.split('-')
  let out = ''
  for (const token of tokens) {
    if ('lit' in token) {
      out += token.lit
      continue
    }
    const source = token.field === 'y' ? year! : token.field === 'm' ? month! : day!
    if (token.width === 4) {
      out += source // YYYY — full 4-digit year
    } else if (token.width === 2) {
      out += source.slice(-2) // YY / MM / DD
    } else {
      out += String(Number(source)) // M / D — strip the leading zero
    }
  }

  return out
}

// Cache the formatter per locale — formatAuto runs once per rendered date cell.
let autoFormatter: Intl.DateTimeFormat | null = null
let autoLocale: string | null = null

function formatAuto(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number) // 4-digit year guaranteed by ISO_SHAPE upstream
  const locale = appConfig().locale
  if (autoFormatter === null || autoLocale !== locale) {
    autoLocale = locale
    autoFormatter = new Intl.DateTimeFormat(locale, { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC' })
  }

  // Build + format in UTC so a date-only value can't drift by a day across a DST
  // boundary in some timezones.
  return autoFormatter.format(new Date(Date.UTC(year!, month! - 1, day!)))
}

/** Format an ISO yyyy-mm-dd date with an explicit preference (pure — used by the
 *  Settings live preview). Falls back to the raw ISO on any malformed input. */
export function formatWith(iso: string, pref: DateFormatPref): string {
  if (!ISO_SHAPE.test(iso)) {
    return iso // not an ISO date (blank / unexpected) — pass through untouched
  }
  try {
    if (pref.mode === 'iso') {
      return iso
    }
    if (pref.mode === 'auto') {
      return formatAuto(iso)
    }
    if (!validatePattern(pref.pattern).ok) {
      return iso
    }

    return applyTokens(iso, tokenize(pref.pattern))
  } catch {
    return iso // never let one bad row break a grid
  }
}

function load(): DateFormatPref {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw !== null) {
      const parsed = JSON.parse(raw) as Partial<DateFormatPref> | null
      if (parsed !== null && typeof parsed === 'object' && (parsed.mode === 'iso' || parsed.mode === 'auto' || parsed.mode === 'custom')) {
        return { mode: parsed.mode, pattern: typeof parsed.pattern === 'string' ? parsed.pattern : DEFAULT_PREF.pattern }
      }
    }
  } catch {
    // localStorage unavailable / malformed — fall through to the default.
  }

  return DEFAULT_PREF
}

const [dateFormat, setDateFormatSignal] = createSignal<DateFormatPref>(load())

export { dateFormat }

export function setDateFormat(pref: DateFormatPref): void {
  setDateFormatSignal(pref)
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(pref))
  } catch {
    // localStorage unavailable — the preference simply won't persist.
  }
}

/** Format an ISO yyyy-mm-dd date with the current preference (reactive). */
export function formatUserDate(iso: string): string {
  return formatWith(iso, dateFormat())
}

const isFieldToken = (token: Token): token is Extract<Token, { field: 'y' | 'm' | 'd' }> => 'field' in token

/** The order the y/m/d fields appear in the active display format (first
 *  occurrence of each), or null when the format is plain ISO (only ISO input is
 *  then accepted) or doesn't carry all three fields. */
export function fieldOrder(pref: DateFormatPref): ('y' | 'm' | 'd')[] | null {
  if (pref.mode === 'iso') {
    return null
  }
  if (pref.mode === 'custom') {
    const seen: ('y' | 'm' | 'd')[] = []
    for (const token of tokenize(pref.pattern)) {
      if (isFieldToken(token) && !seen.includes(token.field)) {
        seen.push(token.field)
      }
    }

    return seen.length === 3 ? seen : null
  }

  // 'auto' — read the field order the locale renders (de → d,m,y; en → m,d,y).
  try {
    const parts = new Intl.DateTimeFormat(appConfig().locale, { year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(new Date(Date.UTC(2001, 11, 25)))
    const order = parts
      .filter((part) => part.type === 'year' || part.type === 'month' || part.type === 'day')
      .map((part) => (part.type === 'year' ? 'y' : part.type === 'month' ? 'm' : 'd') as 'y' | 'm' | 'd')

    return order.length === 3 ? order : ['y', 'm', 'd']
  } catch {
    return ['y', 'm', 'd']
  }
}

/** True when iso (a yyyy-mm-dd string) names a real calendar date (rejects 2026-02-30). */
export function isRealIsoDate(iso: string): boolean {
  const [year, month, day] = iso.split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 1, day!))

  return date.getUTCFullYear() === year && date.getUTCMonth() === month! - 1 && date.getUTCDate() === day
}

/**
 * Parse a user-typed date (in the active display format, or ISO) back to ISO
 * yyyy-mm-dd for the save path. Returns '' for blank input, or null when the
 * value can't be understood as a date. ISO yyyy-mm-dd is ALWAYS accepted as a
 * fallback regardless of the active preference, so the canonical form (or a
 * paste) always works.
 */
export function parseUserDate(input: string, pref: DateFormatPref = dateFormat()): string | null {
  const raw = input.trim()
  if (raw === '') {
    return ''
  }
  if (ISO_SHAPE.test(raw)) {
    return isRealIsoDate(raw) ? raw : null
  }

  const order = fieldOrder(pref)
  if (order === null) {
    return null // ISO-only format and the input wasn't ISO
  }

  // Numeric groups left-to-right. `\d+` is linear (no backtracking → no ReDoS).
  const groups = raw.match(/\d+/g)
  if (groups === null || groups.length < 3) {
    return null
  }
  const part: Record<'y' | 'm' | 'd', string> = { y: '', m: '', d: '' }
  order.forEach((field, index) => {
    part[field] = groups[index]!
  })

  let year = part.y
  if (year.length === 2) {
    year = String(2000 + Number(year)) // 2-digit year → 20YY
  }
  if (!/^\d{4}$/.test(year)) {
    return null
  }
  const iso = `${year}-${part.m.padStart(2, '0')}-${part.d.padStart(2, '0')}`

  return ISO_SHAPE.test(iso) && isRealIsoDate(iso) ? iso : null
}

/** A placeholder hint for a date input in the active format (e.g. "DD.MM.YYYY",
 *  a locale sample, or "YYYY-MM-DD"). */
export function dateFormatPlaceholder(pref: DateFormatPref = dateFormat()): string {
  if (pref.mode === 'custom' && validatePattern(pref.pattern).ok) {
    return pref.pattern
  }
  if (pref.mode === 'auto') {
    return formatAuto('2026-12-31') // a sample so the order/separators are obvious
  }

  return 'YYYY-MM-DD'
}
