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
  logoutUrl: '/logout',
  legacyUrl: '/',
  csrfToken: 'test-csrf-token',
  loginPath: '/login',
}
