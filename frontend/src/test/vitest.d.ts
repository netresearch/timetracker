/* eslint-disable @typescript-eslint/no-empty-object-type --
 * Module augmentation requires interfaces even without own members. */
import type { AxeMatchers } from 'vitest-axe/matchers'

declare module 'vitest' {
  interface Assertion extends AxeMatchers {}
  interface AsymmetricMatchersContaining extends AxeMatchers {}
}
