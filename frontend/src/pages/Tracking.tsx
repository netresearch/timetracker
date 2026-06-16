import { useQuery, useQueryClient } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Show } from 'solid-js'

import { apiErrorMessage, postJson } from '../api/client'
import { activitiesQuery, projectsQuery, trackingCustomersQuery, trackingEntriesQuery, type NamedOption, type TrackingEntry } from '../api/queries'
import type { FieldDef, OptionLookup, OptionSource } from '../admin/types'
import { gridNav } from '../lib/gridNavigation'
import { createInlineGridEdit, InlineEditor, INLINE_TYPES } from '../lib/inlineGridEdit'
import { parseTime, toIsoDate } from '../lib/timeParse'
import { m } from '../paraglide/messages.js'

// Register the directive with the JSX namespace (Solid tree-shakes unused imports).
void gridNav

const DAYS_OPTIONS = [1, 3, 7, 35] as const
const DEFAULT_DAYS = 3
const ENTRIES_KEY = 'tracking-entries'

// Server-computed EntryClass → row modifier, mirroring the ExtJS row borders.
const CLASS_ROW: Record<number, string> = { 2: 'is-daybreak', 4: 'is-pause', 8: 'is-overlap' }

const num = (value: unknown): number => Number(value ?? 0)
const str = (value: unknown): string => (value === undefined || value === null ? '' : String(value))

// The editable fields drive the in-cell editor; duration is server-derived.
const FIELDS: FieldDef[] = [
  { name: 'date', label: () => m.tracking_col_date(), type: 'date', required: true },
  { name: 'start', label: () => m.tracking_col_start(), type: 'text', required: true },
  { name: 'end', label: () => m.tracking_col_end(), type: 'text', required: true },
  { name: 'ticket', label: () => m.tracking_col_ticket(), type: 'text' },
  { name: 'customer', label: () => m.tracking_col_customer(), type: 'select', source: 'customers' },
  { name: 'project', label: () => m.tracking_col_project(), type: 'select', source: 'projects' },
  { name: 'activity', label: () => m.tracking_col_activity(), type: 'select', source: 'activities' },
  { name: 'description', label: () => m.tracking_col_description(), type: 'text' },
]
const FIELD_BY_KEY = new Map(FIELDS.map((field) => [field.name, field]))

const COLUMNS: { key: string; label: () => string; numeric?: boolean }[] = [
  { key: 'date', label: () => m.tracking_col_date() },
  { key: 'start', label: () => m.tracking_col_start(), numeric: true },
  { key: 'end', label: () => m.tracking_col_end(), numeric: true },
  { key: 'ticket', label: () => m.tracking_col_ticket() },
  { key: 'customer', label: () => m.tracking_col_customer() },
  { key: 'project', label: () => m.tracking_col_project() },
  { key: 'activity', label: () => m.tracking_col_activity() },
  { key: 'description', label: () => m.tracking_col_description() },
  { key: 'duration', label: () => m.tracking_col_duration(), numeric: true },
]

// Non-colour cue for the row class (WCAG 1.4.1 / 1.3.1).
function classLabel(entryClass: number): string {
  switch (entryClass) {
    case 2:
      return m.tracking_class_daybreak()
    case 4:
      return m.tracking_class_pause()
    case 8:
      return m.tracking_class_overlap()
    default:
      return ''
  }
}

function nameOf(list: NamedOption[] | undefined, id: number): string {
  if (id <= 0 || list === undefined) {
    return ''
  }

  return list.find((option) => option.id === id)?.label ?? String(id)
}

// d/m/Y (list rows) or Y-m-d (date-input draft) → d/m/Y for display.
function displayDate(value: string): string {
  const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)

  return iso !== null ? `${iso[3]}/${iso[2]}/${iso[1]}` : value
}

/**
 * The SolidJS work-log grid (/ui/tracking), running alongside the legacy ExtJS
 * grid. Inline cell editing reuses the shared inline-grid controller; start/end
 * accept terse times (930, 9:30a) parsed to H:i; saving a row POSTs the whole
 * entry to /tracking/save and refetches (the server recomputes duration + the
 * row class).
 */
export default function Tracking() {
  const queryClient = useQueryClient()
  const [days, setDays] = createSignal<number>(DEFAULT_DAYS)
  const entries = useQuery(() => trackingEntriesQuery(days()))
  const customers = useQuery(trackingCustomersQuery)
  const projects = useQuery(projectsQuery)
  const activities = useQuery(activitiesQuery)

  const rows = createMemo<TrackingEntry[]>(() => entries.data ?? [])

  const optionLookup: OptionLookup = (source: OptionSource) => {
    switch (source) {
      case 'customers':
        return customers.data ?? []
      case 'projects':
        return projects.data ?? []
      case 'activities':
        return activities.data ?? []
      default:
        return []
    }
  }

  const editor = createInlineGridEdit({
    rows,
    fieldFor: (colKey) => FIELD_BY_KEY.get(colKey),
    isInlineEditable: (colKey) => {
      const field = FIELD_BY_KEY.get(colKey)

      return field !== undefined && INLINE_TYPES.has(field.type)
    },
    seedDraft: (entry) => ({
      id: num(entry.id),
      date: toIsoDate(str(entry.date)),
      start: str(entry.start),
      end: str(entry.end),
      ticket: str(entry.ticket),
      customer: num(entry.customer),
      project: num(entry.project),
      activity: num(entry.activity),
      description: str(entry.description),
    }),
    saveRow: async (draft, entry) => {
      const start = parseTime(str(draft.start))
      const end = parseTime(str(draft.end))
      if (start === null || end === null) {
        throw new Error(m.tracking_invalid_time())
      }
      await postJson('/tracking/save', {
        id: num(entry.id),
        date: str(draft.date),
        start,
        end,
        ticket: str(draft.ticket).toUpperCase().trim(),
        description: str(draft.description),
        customer: num(draft.customer),
        project: num(draft.project),
        activity: num(draft.activity),
      })
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
    },
    saveErrorMessage: (caught) => (caught instanceof Error ? caught.message : m.app_load_error()),
  })

  // Display value from the draft-overlaid row, so an edited-but-unsaved cell
  // shows the new value (relation ids resolved to names here, not pre-memoized,
  // because the overlay changes as the draft does).
  function displayCell(entry: TrackingEntry, colKey: string): string {
    const row = editor.overlayRow(entry)
    switch (colKey) {
      case 'date':
        return displayDate(str(row.date))
      case 'start':
        return str(row.start)
      case 'end':
        return str(row.end)
      case 'ticket':
        return str(row.ticket)
      case 'customer':
        return nameOf(customers.data, num(row.customer))
      case 'project':
        return nameOf(projects.data, num(row.project))
      case 'activity':
        return nameOf(activities.data, num(row.activity))
      case 'description':
        return str(row.description)
      case 'duration':
        return str(entry.duration)
      default:
        return ''
    }
  }

  async function removeEntry(entry: TrackingEntry): Promise<void> {
    if (!window.confirm(m.admin_delete_confirm())) {
      return
    }
    try {
      await postJson('/tracking/delete', { id: num(entry.id) })
      // Drop any pending inline draft for the now-deleted entry.
      editor.takeDraft(num(entry.id))
      await queryClient.invalidateQueries({ queryKey: [ENTRIES_KEY] })
    } catch (caught) {
      window.alert(apiErrorMessage(caught, m.app_load_error()))
    }
  }

  let daysSelectEl: HTMLSelectElement | undefined

  return (
    <section class="tracking">
      <h2 class="visually-hidden">{m.tracking_title()}</h2>

      <div class="tracking-toolbar">
        <label class="tracking-days">
          <span>{m.tracking_days_label()}</span>
          <select
            ref={(el) => { daysSelectEl = el }}
            value={String(days())}
            onChange={(event) => setDays(Number(event.currentTarget.value))}
          >
            <For each={DAYS_OPTIONS}>
              {(option) => <option value={String(option)}>{m.tracking_days_option({ count: String(option) })}</option>}
            </For>
          </select>
        </label>
      </div>

      <Show when={!entries.isError} fallback={<p role="alert">{m.app_load_error()}</p>}>
        <div class="table-scroll">
          <table
            class="data-table tracking-table"
            ref={(el) => { editor.setTableEl(el) }}
            onFocusIn={editor.onTableFocusIn}
            onFocusOut={editor.onTableFocusOut}
            use:gridNav={{
              items: rows,
              onExit: (direction) => { if (direction === 'up') daysSelectEl?.focus() },
              onActivate: editor.onActivate,
              moveRef: (handle) => { editor.setMoveHandle(handle) },
            }}
          >
            <thead>
              <tr>
                <For each={COLUMNS}>{(col) => <th scope="col" classList={{ numeric: col.numeric }}>{col.label()}</th>}</For>
                <th scope="col">{m.tracking_actions()}</th>
              </tr>
            </thead>
            <tbody>
              <For each={rows()}>
                {(entry) => {
                  const id = num(entry.id)

                  return (
                    <tr class={`tracking-row ${CLASS_ROW[entry.class] ?? ''}`.trimEnd()} aria-busy={editor.savingRows[id] ? 'true' : undefined}>
                      <For each={COLUMNS}>
                        {(col) => {
                          const editable = FIELD_BY_KEY.has(col.key)

                          return (
                            <td
                              classList={{ numeric: col.numeric, 'is-editable': editable }}
                              data-row-id={String(id)}
                              data-col-key={col.key}
                              data-inline-editing={editor.isEditing(id, col.key) ? '' : undefined}
                              aria-label={editor.isEditing(id, col.key) ? col.label() : undefined}
                              onDblClick={() => { if (editable) editor.beginEdit(id, col.key) }}
                            >
                              <Show
                                when={editor.isEditing(id, col.key)}
                                fallback={
                                  <>
                                    {displayCell(entry, col.key)}
                                    <Show when={col.key === 'date' && classLabel(entry.class) !== ''}>
                                      <span class="visually-hidden"> ({classLabel(entry.class)})</span>
                                    </Show>
                                  </>
                                }
                              >
                                <InlineEditor
                                  field={FIELD_BY_KEY.get(col.key)!}
                                  label={col.label()}
                                  initial={editor.draftValue(id, col.key) ?? ''}
                                  seed={editor.seedChar()}
                                  options={optionLookup}
                                  onCommit={editor.commitCell}
                                  onCancel={editor.cancelCell}
                                />
                              </Show>
                            </td>
                          )
                        }}
                      </For>
                      {/* data-row-id (no data-col-key → not inline-editable) keeps focus
                          "inside the row" so clicking Delete isn't read as a row-leave. */}
                      <td class="tracking-row-actions" data-row-id={String(id)}>
                        <button type="button" class="link-button is-danger" onClick={() => void removeEntry(entry)}>
                          <svg class="action-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                          {m.admin_delete()}
                        </button>
                        <Show when={editor.rowErrors[id]}>
                          <span role="alert" class="form-status is-error">{editor.rowErrors[id]}</span>
                        </Show>
                      </td>
                    </tr>
                  )
                }}
              </For>
            </tbody>
          </table>
        </div>

        <Show when={entries.isLoading}>
          <p class="tracking-loading">{m.app_loading()}</p>
        </Show>
        <Show when={rows().length === 0 && !entries.isLoading}>
          <p class="tracking-empty">{m.tracking_empty()}</p>
        </Show>
      </Show>
    </section>
  )
}
