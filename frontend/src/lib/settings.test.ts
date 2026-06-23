import { describe, expect, it } from 'vitest'

import type { ContractHoursRecord } from '../api/queries'
import { contractHoursPerWeekday, DEFAULT_HOURS_PER_DAY } from './settings'

const CONTRACT: ContractHoursRecord = {
  hours_0: 0, // Sunday
  hours_1: 8, // Monday
  hours_2: 8, // Tuesday
  hours_3: 8, // Wednesday
  hours_4: 8, // Thursday
  hours_5: 4, // Friday (half day)
  hours_6: 0, // Saturday
}

describe('contractHoursPerWeekday', () => {
  it('maps JS getDay() (0=Sun … 6=Sat) directly onto hours_<weekday>', () => {
    const hours = contractHoursPerWeekday(CONTRACT)

    expect(hours(0)).toBe(0) // Sunday
    expect(hours(1)).toBe(8) // Monday
    expect(hours(5)).toBe(4) // Friday
    expect(hours(6)).toBe(0) // Saturday
  })

  it('falls back to 8h per weekday when the record has not loaded yet', () => {
    const hours = contractHoursPerWeekday(undefined)

    for (let weekday = 0; weekday < 7; weekday++) {
      expect(hours(weekday)).toBe(DEFAULT_HOURS_PER_DAY)
    }
    expect(DEFAULT_HOURS_PER_DAY).toBe(8)
  })

  it('falls back to 8h for a missing or non-finite contract value', () => {
    // A malformed record (e.g. NaN from a bad cast) must not poison the expected hours.
    const hours = contractHoursPerWeekday({ ...CONTRACT, hours_2: Number.NaN })

    expect(hours(2)).toBe(DEFAULT_HOURS_PER_DAY)
    // Unaffected weekdays keep their contract value.
    expect(hours(1)).toBe(8)
  })
})
