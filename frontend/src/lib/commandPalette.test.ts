import { describe, expect, it } from 'vitest'

import { registerCommands, registeredCommands, type Command } from './commandPalette'

const make = (id: string): Command => ({ id, group: () => 'g', label: () => id, run: () => undefined })

describe('commandPalette registry', () => {
  it('register adds commands; the disposer removes exactly those', () => {
    const before = registeredCommands().length
    const off = registerCommands([make('a'), make('b')])
    expect(registeredCommands().length).toBe(before + 2)
    expect(registeredCommands().some((command) => command.id === 'a')).toBe(true)

    off()
    expect(registeredCommands().length).toBe(before)
    expect(registeredCommands().some((command) => command.id === 'a')).toBe(false)
  })

  it('independent registrations dispose independently', () => {
    const off1 = registerCommands([make('x')])
    const off2 = registerCommands([make('y')])
    off1()
    expect(registeredCommands().some((command) => command.id === 'y')).toBe(true)
    expect(registeredCommands().some((command) => command.id === 'x')).toBe(false)
    off2()
  })
})
