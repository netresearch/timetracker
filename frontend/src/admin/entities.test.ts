import { describe, expect, it } from 'vitest'

import { adminEntities } from './entities'
import type { ColumnDef, EntityDescriptor, OptionLookup } from './types'

const entities = adminEntities()
const byKey = (key: string): EntityDescriptor => {
  const found = entities.find((entity) => entity.key === key)
  if (!found) throw new Error(`no entity ${key}`)

  return found
}
const noOptions: OptionLookup = () => []
const col = (entity: EntityDescriptor, key: string): ColumnDef => {
  const found = entity.columns.find((c) => c.key === key)
  if (!found) throw new Error(`no column ${key}`)

  return found
}

describe('admin entity descriptors', () => {
  it('user toPayload keeps locale/type as strings (not the numeric select coercion)', () => {
    const payload = byKey('users').toPayload({ id: 3, username: 'u', abbr: 'U', locale: 'en', type: 'PL', teams: [2] })
    expect(payload).toMatchObject({ locale: 'en', type: 'PL' })
    expect(typeof payload.locale).toBe('string')
    expect(typeof payload.type).toBe('string')
  })

  it('project toForm normalizes snake_case and camelCase rows identically (pick)', () => {
    const projects = byKey('projects')
    const snake = projects.toForm({ id: 4, name: 'P', customer: 1, ticket_system: 2, jira_id: 'ABC', cost_center: 'CC', project_lead: 5, technical_lead: 6 })
    const camel = projects.toForm({ id: 4, name: 'P', customer: 1, ticketSystem: 2, jiraId: 'ABC', costCenter: 'CC', projectLead: 5, technicalLead: 6 })

    expect(snake).toEqual(camel)
    expect(snake.ticket_system).toBe(2)
    expect(snake.jiraId).toBe('ABC')
    expect(snake.cost_center).toBe('CC')
  })

  it('toForm(null) returns the documented add-defaults', () => {
    expect(byKey('customers').toForm(null)).toMatchObject({ id: 0, active: true, global: false, teams: [] })
    expect(byKey('activities').toForm(null)).toMatchObject({ factor: 1, needsTicket: false })
    expect(byKey('contracts').toForm(null)).toMatchObject({ hours_1: 8, hours_5: 8, hours_6: 0, hours_0: 0 })
  })

  it('boolean columns render ✓/— as the sort key', () => {
    const active = col(byKey('customers'), 'active')
    expect(active.render?.({ active: true }, noOptions)).toBe('✓')
    expect(active.render?.({ active: false }, noOptions)).toBe('—')
    expect(active.align).toBe('center')
  })

  // The `render` above is only the (hidden) sort key; `boolean: true` is what makes
  // the cell paint the BoolDot. Every boolean-backed column must set it, or it
  // renders the raw ✓/— text instead of the dot (inconsistent with active/global).
  it.each([
    ['customers', 'active'],
    ['customers', 'global'],
    ['projects', 'active'],
    ['projects', 'global'],
    ['ticketsystems', 'bookTime'],
    ['activities', 'needsTicket'],
  ])('%s.%s is a boolean (dot) column', (entityKey, colKey) => {
    expect(col(byKey(entityKey), colKey).boolean).toBe(true)
  })

  it('relation columns resolve id→label, fall back to the id, and blank on 0', () => {
    const customerCol = col(byKey('projects'), 'customer')
    const lookup: OptionLookup = (source) => (source === 'customers' ? [{ id: 1, label: 'ACME' }] : [])

    expect(customerCol.render?.({ customer: 1 }, lookup)).toBe('ACME')
    expect(customerCol.render?.({ customer: 99 }, lookup)).toBe('99')
    expect(customerCol.render?.({ customer: 0 }, lookup)).toBe('')
  })

  it('user locale column shows the language endonym (unknown code passes through)', () => {
    const locale = col(byKey('users'), 'locale')
    expect(locale.render?.({ locale: 'de' }, noOptions)).toBe('Deutsch')
    expect(locale.render?.({ locale: 'en' }, noOptions)).toBe('English')
    expect(locale.render?.({ locale: 'xx' }, noOptions)).toBe('xx')
  })

  it('contract hours column summarises working hours Mon→Sun', () => {
    const hours = col(byKey('contracts'), 'hours_summary')
    // entity keys are JS getDay()-indexed (hours_0 = Sunday); shown Mon→Sun.
    expect(hours.render?.({ hours_1: 8, hours_2: 8, hours_3: 8, hours_4: 8, hours_5: 8, hours_6: 0, hours_0: 0 }, noOptions))
      .toBe('8 / 8 / 8 / 8 / 8 / 0 / 0')
    expect(hours.render?.({ hours_1: 8.5 }, noOptions)).toBe('8.5 / 0 / 0 / 0 / 0 / 0 / 0')
  })

  it('project billing column maps the method code to a label (out-of-range blanks)', () => {
    const billing = col(byKey('projects'), 'billing')
    expect(billing.render?.({ billing: 0 }, noOptions)).toBeTruthy()
    expect(billing.render?.({ billing: 1 }, noOptions)).not.toBe(billing.render?.({ billing: 0 }, noOptions))
    expect(billing.render?.({ billing: 99 }, noOptions)).toBe('')
  })

  it('project lead column resolves the user id→label, blanks on 0', () => {
    const lead = col(byKey('projects'), 'project_lead')
    const users: OptionLookup = (source) => (source === 'users' ? [{ id: 5, label: 'Alice' }] : [])
    expect(lead.render?.({ project_lead: 5 }, users)).toBe('Alice')
    expect(lead.render?.({ projectLead: 5 }, users)).toBe('Alice') // camelCase key also resolves (Base::toArray emits both)
    expect(lead.render?.({ project_lead: 0 }, users)).toBe('')
  })

  it('holidays are immutable (not editable) and delete by day, not id', () => {
    const holidays = byKey('holidays')
    expect(holidays.editable).toBe(false)
    // Delete keys on the day (the entity PK), not the synthetic numeric id.
    expect(holidays.deletePayload?.({ id: 20260101, day: '2026-01-01', name: 'New Year' })).toEqual({ day: '2026-01-01' })
    // Add form starts blank; save payload carries day + name only.
    expect(holidays.toForm(null)).toMatchObject({ day: '', name: '' })
    expect(holidays.toPayload({ id: 0, day: '2026-01-01', name: 'New Year' })).toEqual({ day: '2026-01-01', name: 'New Year' })
  })

  it('customers, projects and users expose a last_activity column (raw ISO date, no custom render)', () => {
    for (const key of ['customers', 'projects', 'users']) {
      // col() throws if the column is missing. No render → the shell's cellText falls
      // back to the raw row value (ISO date, which sorts chronologically) and blanks
      // when the entity was never booked.
      expect(col(byKey(key), 'last_activity').render).toBeUndefined()
    }
  })
})
