/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { For } from 'solid-js'

import type { Shortcut } from '../lib/shortcuts'

/** A captioned key→action table, shared by the /help page and the ?-dialog. */
export function ShortcutTable(props: { caption: string; rows: Shortcut[] }) {
  return (
    <table class="shortcut-table">
      <caption>{props.caption}</caption>
      <tbody>
        <For each={props.rows}>
          {(row) => (
            <tr>
              <th scope="row"><kbd>{row.keys}</kbd></th>
              <td>{row.label()}</td>
            </tr>
          )}
        </For>
      </tbody>
    </table>
  )
}
