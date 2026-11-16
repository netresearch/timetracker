/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { For, Show, type JSX } from 'solid-js'

import type { JiraAuthorizationNotice as Notice } from '../api/queries'
import { m } from '../paraglide/messages.js'

/**
 * #911: the entry saved, but its worklog did not reach Jira because Jira refused
 * the user's token. Unlike the brief save toast this stays until dismissed, is
 * announced (role="status") and carries a real link to the connect route, which
 * redirects to Jira's authorize page; Jira then returns through the OAuth
 * callback.
 */
export function JiraAuthorizationNotice(props: { notices: Notice[]; onDismiss: () => void }): JSX.Element {
  return (
    <Show when={props.notices.length > 0}>
      <div class="jira-auth-notice" role="status">
        <For each={props.notices}>
          {(notice) => (
            <p>
              {m.jira_auth_notice({ ticketSystem: notice.ticketSystemName })}{' '}
              <a href={notice.url}>{m.jira_auth_connect({ ticketSystem: notice.ticketSystemName })}</a>
            </p>
          )}
        </For>
        <button type="button" class="link-button" onClick={() => props.onDismiss()}>
          {m.jira_auth_dismiss()}
        </button>
      </div>
    </Show>
  )
}

/** Merges new notices into the shown ones, one per ticket system. */
export function mergeJiraNotices(current: Notice[], incoming: Notice[] | undefined): Notice[] {
  if (incoming === undefined || incoming.length === 0) {
    return current
  }
  const byId = new Map(current.map((notice) => [notice.ticketSystemId, notice]))
  for (const notice of incoming) {
    byId.set(notice.ticketSystemId, notice)
  }

  return [...byId.values()]
}
