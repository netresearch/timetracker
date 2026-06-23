import type { ContractHoursRecord } from '../api/queries'

// Expected working hours per weekday come from the current user's contract
// (GET /getContractHours), keyed hours_0 (Sunday) … hours_6 (Saturday) to match
// JS Date.getDay(). The previous client-side localStorage stopgap was never
// wired to a settings UI, so it is gone: the contract is the single source of
// truth, with a uniform 8h fallback when no contract value applies.
export const DEFAULT_HOURS_PER_DAY = 8

/**
 * Build a `hoursPerWeekday(weekday)` lookup from a contract-hours record.
 *
 * `weekday` is the JS `Date.getDay()` value (0 = Sunday … 6 = Saturday), which
 * maps 1:1 onto the contract's `hours_<weekday>` field. A missing record (the
 * query hasn't resolved yet) or a non-finite value falls back to 8h, matching
 * the backend's all-8 default for users without a contract.
 */
export function contractHoursPerWeekday(record: ContractHoursRecord | undefined): (weekday: number) => number {
  return (weekday: number): number => {
    // == null guards both undefined (query unresolved) and a null the API could
    // hand back, either of which means "no contract value applies".
    if (record == null) {
      return DEFAULT_HOURS_PER_DAY
    }

    const hours: unknown = record[`hours_${weekday}` as keyof ContractHoursRecord]

    return typeof hours === 'number' && Number.isFinite(hours) ? hours : DEFAULT_HOURS_PER_DAY
  }
}
