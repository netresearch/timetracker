import { useQueryClient, useQuery } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Match, Show, Switch } from 'solid-js'

import { ApiError, getJson, postJson } from '../api/client'
import { m } from '../paraglide/messages.js'
import type { EntityDescriptor, FieldDef, FormValues, OptionLookup } from '../admin/types'

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

  const [editing, setEditing] = createSignal<FormValues | null>(null)
  const [values, setValues] = createSignal<FormValues>({})
  const [error, setError] = createSignal('')
  const [saving, setSaving] = createSignal(false)

  // List payloads are row-wrapped ({customer:{…}}, {user:{…}}, …). Unwrap by the
  // descriptor's rowKey and drop any row whose wrapper is missing or null —
  // handing the grid an undefined row would crash it (reading a column value
  // off undefined).
  const rows = createMemo<Row[]>(() =>
    (list.data ?? [])
      .map((row) => row?.[props.descriptor.rowKey])
      .filter((row): row is Row => row != null && typeof row === 'object'),
  )

  function openForm(row: Row | null) {
    setError('')
    setValues(props.descriptor.toForm(row))
    setEditing(props.descriptor.toForm(row))
  }

  function setField(name: string, value: FormValues[string]) {
    setValues((current) => ({ ...current, [name]: value }))
  }

  async function submit(event: SubmitEvent) {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await postJson(props.descriptor.saveEndpoint, props.descriptor.toPayload(values()))
      await queryClient.invalidateQueries({ queryKey: listKey() })
      setEditing(null)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : m.app_load_error())
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
      await queryClient.invalidateQueries({ queryKey: listKey() })
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : m.app_load_error())
    }
  }

  return (
    <div class="admin-crud">
      <div class="admin-crud-toolbar">
        <button type="button" class="primary-button" onClick={() => openForm(null)}>
          {m.admin_add()}
        </button>
        <Show when={error()}>
          <span role="alert" class="form-status is-error">{error()}</span>
        </Show>
      </div>

      <Show when={!list.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <table class="data-table admin-table">
          <thead>
            <tr>
              <For each={props.descriptor.columns}>{(col) => <th scope="col">{col.label()}</th>}</For>
              <th scope="col">{m.admin_actions()}</th>
            </tr>
          </thead>
          <tbody>
            <For each={rows()}>
              {(row) => (
                <tr>
                  <For each={props.descriptor.columns}>
                    {(col) => <td>{col.render ? col.render(row, props.options) : String(row[col.key] ?? '')}</td>}
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
      </Show>

      <Show when={editing()}>
        <div
          class="modal-backdrop"
          onClick={() => setEditing(null)}
          onKeyDown={(event) => { if (event.key === 'Escape') setEditing(null) }}
        >
          <div
            class="modal"
            role="dialog"
            aria-modal="true"
            aria-label={props.descriptor.title()}
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => { if (event.key === 'Escape') setEditing(null) }}
          >
            <form class="stack-form" onSubmit={(event) => void submit(event)}>
              <For each={props.descriptor.fields}>
                {(field) => <FieldControl field={field} values={values()} setField={setField} options={props.options} editing={editing() !== null && Number(values().id ?? 0) > 0} />}
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
          </div>
        </div>
      </Show>
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
