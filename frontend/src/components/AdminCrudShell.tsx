import { Dialog } from '@ark-ui/solid/dialog'
import { useQueryClient, useQuery } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Match, onCleanup, Show, Switch } from 'solid-js'
import { createStore, reconcile } from 'solid-js/store'
import { Portal } from 'solid-js/web'

import { apiErrorMessage, getJson, postJson } from '../api/client'
import { optionSourceKey } from '../api/queries'
import { gridNav } from '../lib/gridNavigation'
import { m } from '../paraglide/messages.js'
import type { ColumnDef, EntityDescriptor, FieldDef, FormValues, OptionLookup } from '../admin/types'

type Row = Record<string, unknown>

/**
 * Reusable admin CRUD surface: a list grid + add/edit modal form + delete,
 * driven entirely by an EntityDescriptor. The list responses are row-wrapped
 * ({user:{…}} etc.); save/delete go out as typed JSON (#[MapRequestPayload]).
 */
export function AdminCrudShell(props: {
  descriptor: EntityDescriptor
  options: OptionLookup
}) {
  const queryClient = useQueryClient()
  const listKey = () => ['admin-list', props.descriptor.key] as const

  const list = useQuery(() => ({
    queryKey: listKey(),
    queryFn: () => getJson<Row[]>(props.descriptor.listEndpoint),
  }))

  // A save/delete changes both this grid's rows and the shared option source
  // that other entities resolve relation labels from and that every edit-form
  // dropdown reads. The grid and the option source are separate caches of the
  // same endpoint (['admin-list', key] vs ['all-<key>']), so invalidate both —
  // otherwise cross-entity columns and dropdowns keep showing stale labels.
  async function refreshAfterMutation() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: listKey() }),
      queryClient.invalidateQueries({ queryKey: optionSourceKey(props.descriptor.key) }),
    ])
  }

  const [editing, setEditing] = createSignal<FormValues | null>(null)
  // A store (not a signal) so typing in one field updates only that field's
  // control instead of recreating the whole object and re-evaluating every
  // FieldControl on each keystroke.
  const [values, setValues] = createStore<FormValues>({})
  const [error, setError] = createSignal('')
  const [saving, setSaving] = createSignal(false)
  // Transient success confirmation (save/delete) shown in the toolbar.
  const [notice, setNotice] = createSignal('')
  let noticeTimer: ReturnType<typeof setTimeout> | undefined
  function flashNotice(message: string) {
    setNotice(message)
    clearTimeout(noticeTimer)
    noticeTimer = setTimeout(() => setNotice(''), 3000)
  }
  onCleanup(() => clearTimeout(noticeTimer))

  // List payloads are row-wrapped ({customer:{…}}, {user:{…}}, …). Unwrap by the
  // descriptor's rowKey and drop any row whose wrapper is missing or null —
  // handing the grid an undefined row would crash it (reading a column value
  // off undefined).
  const rows = createMemo<Row[]>(() =>
    (list.data ?? [])
      .map((row) => row?.[props.descriptor.rowKey])
      .filter((row): row is Row => row != null && typeof row === 'object'),
  )

  // Column sorting. cellText() yields exactly what the grid shows (id→label,
  // ✓/—, …) so the sort order matches the visible values. Clicking a header
  // cycles none → ascending → descending → none.
  const [sort, setSort] = createSignal<{ key: string; dir: 'asc' | 'desc' } | null>(null)
  // Free-text filter: matches against the visible text of every column.
  const [filter, setFilter] = createSignal('')

  const cellText = (row: Row, col: ColumnDef): string =>
    col.render ? col.render(row, props.options) : String(row[col.key] ?? '')

  function toggleSort(key: string) {
    setSort((current) =>
      current?.key !== key ? { key, dir: 'asc' } : current.dir === 'asc' ? { key, dir: 'desc' } : null,
    )
  }

  const ariaSort = (key: string): 'ascending' | 'descending' | 'none' => {
    const current = sort()

    return current?.key === key ? (current.dir === 'asc' ? 'ascending' : 'descending') : 'none'
  }

  // Active column shows its direction; the rest show a dim neutral cue so
  // sortability is discoverable at rest (incl. on touch, where there's no hover).
  const sortGlyph = (key: string): string => {
    const current = sort()

    return current?.key === key ? (current.dir === 'asc' ? '▲' : '▼') : '⇅'
  }

  // Decorate each row with its full-text haystack ONCE per data/columns/options
  // change — not per keystroke. Filtering then only runs `.includes` on these
  // precomputed strings, so typing in the filter box stays cheap on big lists.
  const decorated = createMemo(() => {
    const columns = props.descriptor.columns

    return rows().map((row) => ({
      row,
      haystack: columns.map((col) => cellText(row, col)).join(' ').toLowerCase(),
    }))
  })

  const visibleRows = createMemo<Row[]>(() => {
    const current = sort()
    const query = filter().trim().toLowerCase()
    const matched = query === '' ? decorated() : decorated().filter((entry) => entry.haystack.includes(query))
    const sortCol = current && props.descriptor.columns.find((c) => c.key === current.key)
    if (!current || !sortCol) {
      return matched.map((entry) => entry.row)
    }
    const factor = current.dir === 'asc' ? 1 : -1

    return matched
      .map((entry) => ({ row: entry.row, key: cellText(entry.row, sortCol) }))
      .sort((a, b) => factor * a.key.localeCompare(b.key, undefined, { numeric: true }))
      .map((entry) => entry.row)
  })

  function openForm(row: Row | null) {
    setError('')
    const form = props.descriptor.toForm(row)
    // reconcile replaces every key (and drops stale ones) in one diffed update.
    setValues(reconcile(form))
    setEditing(form)
  }

  function setField(name: string, value: FormValues[string]) {
    setValues(name, value)
  }

  async function submit(event: SubmitEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await postJson(props.descriptor.saveEndpoint, props.descriptor.toPayload({ ...values }))
      await refreshAfterMutation()
      setEditing(null)
      flashNotice(m.admin_saved())
    } catch (caught) {
      setError(apiErrorMessage(caught, m.app_load_error()))
    } finally {
      setSaving(false)
    }
  }

  async function remove(row: Row) {
    if (!window.confirm(`${m.admin_delete_confirm()}\n${props.descriptor.rowLabel(row)}`)) {
      return
    }
    try {
      await postJson(props.descriptor.deleteEndpoint, { id: row.id })
      await refreshAfterMutation()
      flashNotice(m.admin_deleted())
    } catch (caught) {
      setError(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  let searchEl: HTMLInputElement | undefined

  return (
    <div class="admin-crud">
      <div class="admin-crud-toolbar">
        <button type="button" class="primary-button" data-keyboard-add aria-keyshortcuts="Alt+A" onClick={() => openForm(null)}>
          {m.admin_add()}
        </button>
        <Show when={error()}>
          <span role="alert" class="form-status is-error">{error()}</span>
        </Show>
        <Show when={notice()}>
          <span role="status" class="form-status is-ok">{notice()}</span>
        </Show>
        <input
          ref={(el) => { searchEl = el }}
          type="search"
          class="admin-filter"
          placeholder={m.admin_filter()}
          aria-label={m.admin_filter()}
          aria-keyshortcuts="/"
          value={filter()}
          onInput={(event) => setFilter(event.currentTarget.value)}
          onKeyDown={(event) => {
            // The *active* entity, not merely the first (a grouped selector
            // would match the first DOM element of either kind).
            const subnav = () => document.querySelector<HTMLElement>('.admin-subnav-link[aria-current="page"]')
              ?? document.querySelector<HTMLElement>('.admin-subnav-link')
            if (event.key === 'ArrowUp') {
              // ArrowUp hands focus up to the entity sub-navigation (ArrowDown
              // back into the table is handled globally in header.ts).
              const el = subnav()
              if (el !== null) {
                event.preventDefault()
                el.focus()
              }
            } else if (event.key === 'Escape') {
              // Conventional Escape: clear the filter if it has text, else leave
              // the field back up to the sub-nav — never descend into the grid.
              event.preventDefault()
              if (filter() !== '') {
                setFilter('')
              } else {
                subnav()?.focus()
              }
            }
          }}
        />
      </div>

      <Show when={!list.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table admin-table"
            use:gridNav={{ items: visibleRows, onExit: (dir) => { if (dir === 'up') searchEl?.focus() } }}
          >
            <thead>
              <tr>
                <For each={props.descriptor.columns}>
                  {(col) => (
                    <th
                      scope="col"
                      classList={{ numeric: col.align === 'right', boolean: col.align === 'center' }}
                      aria-sort={ariaSort(col.key)}
                    >
                      <button type="button" class="th-sort" onClick={() => toggleSort(col.key)}>
                        <span>{col.label()}</span>
                        <span class="th-sort-glyph" aria-hidden="true">{sortGlyph(col.key)}</span>
                      </button>
                    </th>
                  )}
                </For>
                <th scope="col">{m.admin_actions()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={visibleRows()}>
                {(row) => (
                  <tr>
                    <For each={props.descriptor.columns}>
                      {(col) => (
                        <td classList={{ numeric: col.align === 'right', boolean: col.align === 'center' }}>
                          {cellText(row, col)}
                        </td>
                      )}
                    </For>
                    <td class="admin-row-actions">
                      <button type="button" class="link-button" onClick={() => openForm(row)}>{m.admin_edit()}</button>
                      <button type="button" class="link-button is-danger" onClick={() => void remove(row)}>{m.admin_delete()}</button>
                    </td>
                  </tr>
                )}
              </For>
            </tbody>
          </table>
        </div>
        <Show when={filter().trim() !== '' && visibleRows().length === 0}>
          <p role="status" class="effort-empty admin-no-matches">{m.admin_no_matches()}</p>
        </Show>
        <Show when={filter().trim() === '' && !list.isPending && visibleRows().length === 0}>
          <p class="effort-empty admin-no-matches">{m.admin_empty()}</p>
        </Show>
      </Show>

      {/* Ark UI Dialog gives the edit form a real focus trap, focus-on-open,
          focus-return to the triggering button, scroll lock and Escape/outside
          dismissal — handled by the library rather than the previous hand-rolled
          backdrop. */}
      <Dialog.Root
        open={editing() !== null}
        onOpenChange={(details) => { if (!details.open) setEditing(null) }}
        lazyMount
        unmountOnExit
      >
        <Portal>
          <Dialog.Backdrop class="modal-backdrop" />
          <Dialog.Positioner class="modal-positioner">
            <Dialog.Content class="modal" aria-label={props.descriptor.title()}>
              <form class="stack-form" onSubmit={(event) => void submit(event)}>
                <For each={props.descriptor.fields}>
                  {(field) => <FieldControl field={field} values={values} setField={setField} options={props.options} editing={editing() !== null && Number(values.id ?? 0) > 0} />}
                </For>
                <div class="form-actions">
                  <button type="submit" class="primary-button" disabled={saving()}>
                    {saving() ? m.app_saving() : m.app_save()}
                  </button>
                  <button type="button" class="action-button" onClick={() => setEditing(null)}>{m.admin_cancel()}</button>
                  <Show when={error()}>
                    <span role="alert" class="form-status is-error">{error()}</span>
                  </Show>
                </div>
              </form>
            </Dialog.Content>
          </Dialog.Positioner>
        </Portal>
      </Dialog.Root>
    </div>
  )
}

function FieldControl(props: {
  field: FieldDef
  values: FormValues
  setField: (name: string, value: FormValues[string]) => void
  options: OptionLookup
  editing: boolean
}) {
  const value = () => props.values[props.field.name]
  const disabled = () => props.editing && props.field.lockedOnEdit === true
  const text = () => String(value() ?? '')

  const selectOptions = createMemo<{ value: string | number; label: string }[]>(() =>
    props.field.staticOptions
      ? props.field.staticOptions.map((option) => ({ value: option.value, label: option.label() }))
      : props.field.source
        ? props.options(props.field.source).map((option) => ({ value: option.id, label: option.label }))
        : [],
  )

  const toggleMulti = (optionValue: number, checked: boolean) => {
    const current = new Set((props.values[props.field.name] as number[] | undefined) ?? [])
    if (checked) {
      current.add(optionValue)
    } else {
      current.delete(optionValue)
    }
    props.setField(props.field.name, [...current])
  }

  return (
    <Switch>
      <Match when={props.field.type === 'checkbox'}>
        <label class="field-check">
          <input type="checkbox" checked={Boolean(value())} onInput={(e) => props.setField(props.field.name, e.currentTarget.checked)} />
          <span>{props.field.label()}</span>
        </label>
      </Match>
      <Match when={props.field.type === 'multiselect'}>
        <fieldset class="field multiselect">
          <legend>{props.field.label()}</legend>
          <For each={selectOptions()}>
            {(option) => (
              <label class="field-check">
                <input
                  type="checkbox"
                  checked={((value() as number[] | undefined) ?? []).includes(Number(option.value))}
                  onInput={(e) => toggleMulti(Number(option.value), e.currentTarget.checked)}
                />
                <span>{option.label}</span>
              </label>
            )}
          </For>
        </fieldset>
      </Match>
      <Match when={props.field.type === 'select'}>
        <label class="field">
          <span>{props.field.label()}</span>
          <select
            required={props.field.required}
            disabled={disabled()}
            value={props.field.stringValue ? String(value() ?? '') : Number(value() ?? 0)}
            onInput={(e) => props.setField(props.field.name, props.field.stringValue ? e.currentTarget.value : Number(e.currentTarget.value))}
          >
            <option value={props.field.stringValue ? '' : 0}>—</option>
            <For each={selectOptions()}>{(option) => <option value={option.value}>{option.label}</option>}</For>
          </select>
        </label>
      </Match>
      <Match when={props.field.type === 'textarea'}>
        <label class="field">
          <span>{props.field.label()}</span>
          <textarea disabled={disabled()} value={text()} onInput={(e) => props.setField(props.field.name, e.currentTarget.value)} />
        </label>
      </Match>
      <Match when={true}>
        <label class="field">
          <span>{props.field.label()}</span>
          <input
            type={props.field.type === 'number' ? 'number' : props.field.type === 'date' ? 'date' : 'text'}
            required={props.field.required}
            disabled={disabled()}
            value={text()}
            onInput={(e) => props.setField(props.field.name, props.field.type === 'number' ? Number(e.currentTarget.value) : e.currentTarget.value)}
          />
        </label>
      </Match>
    </Switch>
  )
}
