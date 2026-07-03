import { afterEach, describe, expect, it } from 'vitest'

import { appConfig, canBill, canBulkEnter, hasRole, needsTwoFactorEnrolment } from './config'

const original = window.APP_CONFIG

afterEach(() => {
  window.APP_CONFIG = original
})

function setRoles(roles: string[]) {
  window.APP_CONFIG = { ...original!, roles }
}

describe('config role helpers', () => {
  it('plain user: no billing, but may bulk-enter, not admin', () => {
    setRoles(['ROLE_USER'])
    expect(hasRole('ROLE_ADMIN')).toBe(false)
    expect(canBill()).toBe(false)
    expect(canBulkEnter()).toBe(true)
  })

  it('project lead: may bill and bulk-enter', () => {
    setRoles(['ROLE_USER', 'ROLE_PL'])
    expect(canBill()).toBe(true)
    expect(canBulkEnter()).toBe(true)
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

describe('needsTwoFactorEnrolment', () => {
  it('is false when 2FA is not required', () => {
    window.APP_CONFIG = { ...original!, twoFactorRequired: false, hasTwoFactor: false }
    expect(needsTwoFactorEnrolment()).toBe(false)
  })

  it('is false when required but the user already has a second factor', () => {
    window.APP_CONFIG = { ...original!, twoFactorRequired: true, hasTwoFactor: true }
    expect(needsTwoFactorEnrolment()).toBe(false)
  })

  it('is true only when required and the user has no second factor', () => {
    window.APP_CONFIG = { ...original!, twoFactorRequired: true, hasTwoFactor: false }
    expect(needsTwoFactorEnrolment()).toBe(true)
  })
})
