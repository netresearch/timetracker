/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import '@testing-library/jest-dom/vitest'
import { expect } from 'vitest'
import * as axeMatchers from 'vitest-axe/matchers'

expect.extend(axeMatchers)

// Node >= 22 ships an experimental global localStorage that shadows jsdom's
// Storage and is non-functional without --localstorage-file; replace it with
// a working in-memory implementation for the test environment.
class MemoryStorage implements Storage {
  readonly #data = new Map<string, string>()

  get length(): number {
    return this.#data.size
  }

  clear(): void {
    this.#data.clear()
  }

  getItem(key: string): string | null {
    return this.#data.get(key) ?? null
  }

  key(index: number): string | null {
    return [...this.#data.keys()][index] ?? null
  }

  removeItem(key: string): void {
    this.#data.delete(key)
  }

  setItem(key: string, value: string): void {
    this.#data.set(key, String(value))
  }
}

Object.defineProperty(window, 'localStorage', {
  value: new MemoryStorage(),
  configurable: true,
})

// jsdom has no ResizeObserver; Ark UI's Zag machines expect it. The methods are
// intentional no-ops — jsdom never lays out, so there is nothing to observe.
class ResizeObserverStub implements ResizeObserver {
  // Constructed as `new ResizeObserver(callback)`; accepting it keeps the stub's
  // shape honest (CodeQL flagged the superfluous argument). The callback is never
  // invoked — jsdom does not lay out, so nothing ever resizes.
  constructor(readonly callback?: ResizeObserverCallback) {}

  observe(): void {
    /* no-op: jsdom does not lay out elements */
  }

  unobserve(): void {
    /* no-op */
  }

  disconnect(): void {
    /* no-op */
  }
}

window.ResizeObserver ??= ResizeObserverStub

// jsdom doesn't implement scrollIntoView; gridNav calls it after focusing a cell
// (a no-op here — jsdom never lays out, so there is nothing to scroll into view).
HTMLElement.prototype.scrollIntoView ??= function scrollIntoView(): void {
  /* no-op: jsdom does not lay out elements */
}

window.APP_CONFIG = {
  locale: 'en',
  userId: 1,
  userName: 'unittest',
  appTitle: 'TimeTracker',
  roles: ['ROLE_USER', 'ROLE_PL', 'ROLE_ADMIN'],
  showEmptyLine: false,
  suggestTime: false,
  showFuture: false,
  minEntryDuration: 5,
  personioSyncEnabled: false,
  personioConfigured: true,
  totpEnabled: false,
  localAccount: true,
  twoFactorRequired: false,
  hasTwoFactor: false,
  logoutUrl: '/logout',
  csrfToken: 'test-csrf-token',
  loginPath: '/login',
}
