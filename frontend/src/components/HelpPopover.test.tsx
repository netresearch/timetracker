/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { cleanup, fireEvent, render, screen } from '@solidjs/testing-library'
import { afterEach, describe, expect, it } from 'vitest'
import { axe } from 'vitest-axe'

import { HelpPopover } from './HelpPopover'

describe('HelpPopover', () => {
  afterEach(cleanup)
  it('opens on click and shows the content', async () => {
    render(() => <HelpPopover topic="Passkeys">Explanation text</HelpPopover>)

    fireEvent.click(screen.getByRole('button', { name: /Passkeys/ }))

    expect(await screen.findByText('Explanation text')).toBeInTheDocument()
  })

  it('has no axe violations when open', async () => {
    const { container } = render(() => (
      <HelpPopover topic="Passkeys">Explanation text</HelpPopover>
    ))
    fireEvent.click(screen.getByRole('button', { name: /Passkeys/ }))
    await screen.findByText('Explanation text')

    expect(await axe(container)).toHaveNoViolations()
  })
})
