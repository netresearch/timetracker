/** Coerce an unknown value to a number, treating null/undefined as 0. */
export const num = (value: unknown): number => Number(value ?? 0)

/** Coerce an unknown value to a string, treating null/undefined as ''. */
export const str = (value: unknown): string => (value === undefined || value === null ? '' : String(value))
