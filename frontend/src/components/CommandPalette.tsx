import { Dialog } from '@ark-ui/solid/dialog'
import { useNavigate } from '@solidjs/router'
import { createEffect, createMemo, createSignal, For, Show } from 'solid-js'
import { Portal } from 'solid-js/web'

import { appConfig, canBill, hasRole } from '../config'
import { paletteOpen, registeredCommands, setPaletteOpen, type Command } from '../lib/commandPalette'
import { setShortcutsHelpOpen } from '../lib/shortcutsHelp'
import { m } from '../paraglide/messages.js'

/**
 * Ctrl/⌘+K command palette: a searchable list of every action — navigation,
 * logout, theme/density, and the current page's context commands (registered via
 * registerCommands). The single discoverable surface for the whole app's
 * keyboard actions, so no shortcut has to be memorised.
 */
export function CommandPalette() {
  const navigate = useNavigate()
  const [query, setQuery] = createSignal('')
  const [active, setActive] = createSignal(0)

  const close = (): void => {
    setPaletteOpen(false)
    setQuery('')
    setActive(0)
  }
  const go = (path: string): void => {
    navigate(path)
  }
  const clickById = (id: string): void => {
    document.getElementById(id)?.click()
  }
  // Run a command, then always close — so every command (including page-registered
  // ones whose run() doesn't close) leaves no lingering overlay or focus trap.
  const runCommand = (command: Command): void => {
    command.run()
    close()
  }

  // Navigation + app-wide commands the palette always offers. Role-gated entries
  // mirror the nav guards; theme/density reuse the shared header's toggle buttons.
  const globalCommands = (): Command[] => {
    const nav = m.cmd_group_nav
    const app = m.cmd_group_app
    const list: Command[] = [
      { id: 'nav-month', group: nav, label: () => m.month_title(), run: () => go('/month') },
      { id: 'nav-tracking', group: nav, label: () => m.tracking_title(), run: () => go('/tracking') },
      { id: 'nav-auswertung', group: nav, label: () => m.auswertung_title(), run: () => go('/auswertung') },
    ]
    if (hasRole('ROLE_ADMIN')) {
      list.push({ id: 'nav-admin', group: nav, label: () => m.admin_title(), run: () => go('/admin') })
    }
    if (canBill()) {
      list.push({ id: 'nav-billing', group: nav, label: () => m.billing_title(), run: () => go('/billing') })
    }
    list.push(
      { id: 'nav-settings', group: nav, label: () => m.settings_title(), run: () => go('/settings') },
      { id: 'nav-help', group: nav, label: () => m.help_title(), run: () => go('/help') },
      { id: 'shortcuts', group: app, label: () => m.cmd_shortcuts(), shortcut: '?', run: () => setShortcutsHelpOpen(true) },
      { id: 'theme', group: app, label: () => m.cmd_theme(), run: () => clickById('theme-cycle') },
      { id: 'density', group: app, label: () => m.cmd_density(), run: () => clickById('density-toggle') },
      { id: 'logout', group: app, label: () => m.cmd_logout(), run: () => window.location.assign(appConfig().logoutUrl) },
    )

    return list
  }

  const commands = createMemo<Command[]>(() => [...globalCommands(), ...registeredCommands()])
  const results = createMemo<Command[]>(() => {
    const q = query().trim().toLowerCase()
    const all = commands()
    if (q === '') {
      return all
    }

    return all.filter((command) =>
      `${command.label()} ${command.group()} ${command.keywords?.() ?? ''}`.toLowerCase().includes(q),
    )
  })

  const clampActive = (index: number): number => Math.max(0, Math.min(index, results().length - 1))

  // Keep the highlighted row visible while arrow-navigating a list that overflows.
  createEffect(() => {
    const command = results()[active()]
    if (command) document.getElementById(`command-${command.id}`)?.scrollIntoView({ block: 'nearest' })
  })

  const onInputKeyDown = (event: KeyboardEvent): void => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault()
      close()
    } else if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActive((index) => clampActive(index + 1))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((index) => clampActive(index - 1))
    } else if (event.key === 'Enter') {
      event.preventDefault()
      const command = results()[active()]
      if (command) runCommand(command)
    }
  }

  return (
    <Show when={paletteOpen()}>
      <Dialog.Root open onOpenChange={(details) => { if (!details.open) close() }} lazyMount unmountOnExit>
        <Portal>
          <Dialog.Backdrop class="modal-backdrop" />
          <Dialog.Positioner class="modal-positioner command-positioner">
            <Dialog.Content class="command-palette" aria-label={m.cmd_title()}>
              <input
                type="text"
                class="command-input"
                autofocus
                autocomplete="off"
                role="combobox"
                aria-expanded="true"
                aria-controls="command-list"
                aria-activedescendant={results()[active()] ? `command-${results()[active()]!.id}` : undefined}
                placeholder={m.cmd_placeholder()}
                value={query()}
                onInput={(event) => { setQuery(event.currentTarget.value); setActive(0) }}
                onKeyDown={onInputKeyDown}
              />
              <ul id="command-list" class="command-list" role="listbox" aria-label={m.cmd_title()}>
                <For each={results()}>
                  {(command, index) => (
                    <li
                      id={`command-${command.id}`}
                      role="option"
                      aria-selected={index() === active()}
                      class={index() === active() ? 'command-item is-active' : 'command-item'}
                      onMouseEnter={() => setActive(index())}
                      onMouseDown={(event) => { event.preventDefault(); runCommand(command) }}
                    >
                      <span class="command-label">{command.label()}</span>
                      <span class="command-group">{command.group()}</span>
                      <Show when={command.shortcut}><kbd class="command-kbd">{command.shortcut}</kbd></Show>
                    </li>
                  )}
                </For>
                <Show when={results().length === 0}>
                  <li class="command-empty">{m.cmd_no_results()}</li>
                </Show>
              </ul>
            </Dialog.Content>
          </Dialog.Positioner>
        </Portal>
      </Dialog.Root>
    </Show>
  )
}
