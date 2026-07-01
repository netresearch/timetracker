/**
 * Find the smallest "thinning level" in `[0, max]` at which a table fits.
 *
 * `overflowsAt(level)` applies that level to the DOM and reports whether the
 * table still overflows its container. The table width is monotonic in the
 * level — each successive level only truncates or hides columns, so a higher
 * level never widens the table — which lets a binary search find the minimal
 * fitting level in ⌈log2(max + 1)⌉ probes instead of a linear scan. That turns
 * the worst-case ~15 synchronous layout reflows per resize into ~4.
 *
 * If the table never fits (overflows even at `max`), `max` is returned — the
 * caller falls back to horizontal scroll at that level.
 */
export function smallestFittingLevel(max: number, overflowsAt: (level: number) => boolean): number {
  let low = 0
  let high = max
  let best = max
  while (low <= high) {
    const mid = Math.floor((low + high) / 2)
    if (overflowsAt(mid)) {
      low = mid + 1
    } else {
      best = mid
      high = mid - 1
    }
  }
  return best
}
