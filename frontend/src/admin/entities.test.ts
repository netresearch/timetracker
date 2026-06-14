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

  it('boolean columns render ✓/—', () => {
    const active = col(byKey('customers'), 'active')
    expect(active.render?.({ active: true }, noOptions)).toBe('✓')
    expect(active.render?.({ active: false }, noOptions)).toBe('—')
    expect(active.align).toBe('center')
  })

  it('relation columns resolve id→label, fall back to the id, and blank on 0', () => {
    const customerCol = col(byKey('projects'), 'customer')
    const lookup: OptionLookup = (source) => (source === 'customers' ? [{ id: 1, label: 'ACME' }] : [])

    expect(customerCol.render?.({ customer: 1 }, lookup)).toBe('ACME')
    expect(customerCol.render?.({ customer: 99 }, lookup)).toBe('99')
    expect(customerCol.render?.({ customer: 0 }, lookup)).toBe('')
  })
})
