import { render } from '@solidjs/testing-library'
import { describe, expect, it } from 'vitest'
import { axe } from 'vitest-axe'

import { EntrySourceBadge } from './EntrySourceBadge'

describe('EntrySourceBadge', () => {
  it('shows the Agent badge for a machine-source entry', () => {
    const { container, getByText, unmount } = render(() => <EntrySourceBadge source="agent" estimated={false} />)

    expect(getByText('Agent')).toBeInTheDocument()
    expect(container.querySelector('.entry-badge.is-agent')).not.toBeNull()
    expect(container.querySelector('.entry-badge.is-estimated')).toBeNull()
    unmount()
  })

  it('shows the estimated badge, independent of source', () => {
    const { container, getByText, unmount } = render(() => <EntrySourceBadge source="human" estimated={true} />)

    expect(getByText('estimated')).toBeInTheDocument()
    expect(container.querySelector('.entry-badge.is-estimated')).not.toBeNull()
    expect(container.querySelector('.entry-badge.is-agent')).toBeNull()
    unmount()
  })

  it('shows both badges when an agent entry is also estimated', () => {
    const { container, unmount } = render(() => <EntrySourceBadge source="agent" estimated={true} />)

    expect(container.querySelector('.entry-badge.is-agent')).not.toBeNull()
    expect(container.querySelector('.entry-badge.is-estimated')).not.toBeNull()
    unmount()
  })

  it('renders nothing for a plain human, non-estimated entry', () => {
    const { container, unmount } = render(() => <EntrySourceBadge source="human" estimated={false} />)

    expect(container.querySelector('.entry-badge')).toBeNull()
    unmount()
  })

  it('does not lean on colour alone — each badge carries text', () => {
    const { getByText, unmount } = render(() => <EntrySourceBadge source="agent" estimated={true} />)

    // Text labels (not just the coloured pill) make the state screen-reader legible.
    expect(getByText('Agent')).toBeInTheDocument()
    expect(getByText('estimated')).toBeInTheDocument()
    unmount()
  })

  it('has no axe violations', async () => {
    const { container, unmount } = render(() => <EntrySourceBadge source="agent" estimated={true} />)

    expect(await axe(container)).toHaveNoViolations()
    unmount()
  })
})
