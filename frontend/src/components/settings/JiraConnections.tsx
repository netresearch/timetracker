/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { useQuery } from '@tanstack/solid-query'
import { For, Show, type JSX } from 'solid-js'

import { jiraConnectionsQuery, type JiraConnection } from '../../api/jiraConnections'
import { m } from '../../paraglide/messages.js'

/**
 * Settings → "connect Jira" (#911). Lists every Jira ticket system that books
 * worklogs, whether or not the user ever connected it, with a plain link to the
 * connect route (it redirects to Jira's authorize page, so no popup can be
 * blocked). "Connected" only means a token is stored — Jira may still refuse
 * it — so reconnecting stays offered.
 */
export function JiraConnections(): JSX.Element {
  const connections = useQuery(jiraConnectionsQuery)
  const list = (): JiraConnection[] => connections.data?.connections ?? []

  return (
    <div class="stack-form">
      <fieldset class="settings-group">
        <legend>{m.jira_connections_title()}</legend>
        <p class="settings-section-hint">{m.jira_connections_intro()}</p>

        <Show when={connections.isError}>
          <p role="alert" class="form-status is-error">{m.jira_connections_error()}</p>
        </Show>
        <Show
          when={list().length > 0}
          fallback={
            <Show when={connections.isSuccess}>
              <p class="field-hint">{m.jira_connections_none()}</p>
            </Show>
          }
        >
          <For each={list()}>
            {(connection) => (
              <div class="security-block">
                <h3 class="security-heading">{connection.ticket_system_name}</h3>
                <p class="field-hint">
                  {connection.connected ? m.jira_connections_connected() : m.jira_connections_not_connected()}
                </p>
                <p>
                  <a class="primary-button" href={connection.connect_url}>
                    {connection.connected ? m.jira_connections_reconnect() : m.jira_connections_connect()}
                  </a>
                </p>
              </div>
            )}
          </For>
        </Show>
      </fieldset>
    </div>
  )
}
