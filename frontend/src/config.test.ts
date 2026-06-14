import { afterEach, describe, expect, it } from 'vitest'

import { appConfig, canBill, canBulkEnter, hasRole } from './config'

const original = window.APP_CONFIG

afterEach(() => {
  window.APP_CONFIG = original
})

function setRoles(roles: string[]) {
  window.APP_CONFIG = { ...original!, roles }
}

describe('config role helpers', () => {
  it('plain user: no billing, no bulk entry, not admin', () => {
    setRoles(['ROLE_USER'])
    expect(hasRole('ROLE_ADMIN')).toBe(false)
    expect(canBill()).toBe(false)
    expect(canBulkEnter()).toBe(false)
  })

  it('project lead: may bill, may not bulk-enter', () => {
    setRoles(['ROLE_USER', 'ROLE_PL'])
    expect(canBill()).toBe(true)
    expect(canBulkEnter()).toBe(false)
  })

  it('admin: may bill and bulk-enter', () => {
    setRoles(['ROLE_USER', 'ROLE_ADMIN'])
    expect(canBill()).toBe(true)
    expect(canBulkEnter()).toBe(true)
  })

  it('throws when APP_CONFIG is missing', () => {
    window.APP_CONFIG = undefined
    expect(() => appConfig()).toThrow(/APP_CONFIG is missing/)
  })
})
