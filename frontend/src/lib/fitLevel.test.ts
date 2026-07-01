import { describe, expect, it } from 'vitest'

import { smallestFittingLevel } from './fitLevel'

// A monotonic width model: the table is `natural` wide at level 0 and each level
// shaves `perLevel` off, so it fits once `natural - level * perLevel <= container`.
function overflowModel(natural: number, perLevel: number, container: number): (level: number) => boolean {
  return (level) => natural - level * perLevel > container
}

describe('smallestFittingLevel', () => {
  it('returns 0 when the table already fits at level 0', () => {
    expect(smallestFittingLevel(14, () => false)).toBe(0)
  })

  it('returns max when the table never fits', () => {
    expect(smallestFittingLevel(14, () => true)).toBe(14)
  })

  it('returns the minimal level that fits', () => {
    // 1000 wide, 100 off per level, 640 container → fits at level 4 (1000-400=600<=640).
    expect(smallestFittingLevel(14, overflowModel(1000, 100, 640))).toBe(4)
  })

  it('returns the exact boundary level, not one past it', () => {
    // Fits exactly at level 3 (1000-300=700<=700), still overflows at 2 (800>700).
    expect(smallestFittingLevel(14, overflowModel(1000, 100, 700))).toBe(3)
  })

  it('probes O(log n) times, not linearly', () => {
    let probes = 0
    const overflowsAt = (level: number): boolean => {
      probes += 1
      return overflowModel(1000, 100, 640)(level)
    }
    smallestFittingLevel(14, overflowsAt)
    // ⌈log2(15)⌉ = 4 → the search must stay well under a 15-step linear scan.
    expect(probes).toBeLessThanOrEqual(4)
  })

  it('matches a brute-force linear scan across every container width', () => {
    const max = 14
    for (let container = 0; container <= 1000; container += 37) {
      const overflowsAt = overflowModel(1000, 70, container)
      let linear = max
      for (let level = 0; level <= max; level += 1) {
        if (!overflowsAt(level)) {
          linear = level
          break
        }
      }
      expect(smallestFittingLevel(max, overflowsAt)).toBe(linear)
    }
  })
})
