import '@testing-library/jest-dom/vitest'
import { expect } from 'vitest'
import * as axeMatchers from 'vitest-axe/matchers'

expect.extend(axeMatchers)

// Node >= 22 ships an experimental global localStorage that shadows jsdom's
// Storage and is non-functional without --localstorage-file; replace it with
// a working in-memory implementation for the test environment.
class MemoryStorage implements Storage {
  #data = new Map<string, string>()

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

window.APP_CONFIG = {
  locale: 'en',
  userId: 1,
  userName: 'unittest',
  appTitle: 'TimeTracker',
  logoutUrl: '/logout',
  legacyUrl: '/',
}
