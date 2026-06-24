import type { NamedOption } from '../api/queries'

/** Named, shared dropdown sources an admin form field can reference. */
export type OptionSource = 'customers' | 'projects' | 'users' | 'teams' | 'ticketSystems' | 'activities'

export interface ColumnDef {
  key: string
  label: () => string
  /** Render a cell from the raw row object (e.g. id→name). For a `boolean`
   *  column this is the (invisible) sort key only — the cell shows a dot. */
  render?: (row: Record<string, unknown>, options: OptionLookup) => string
  /** Cell/header alignment. Numbers → 'right', booleans → 'center'. */
  align?: 'left' | 'right' | 'center'
  /** Render the cell as an on/off indicator (green dot for true, empty for
   *  false) instead of text; `render` still supplies the sort key. */
  boolean?: boolean
}

export type FieldType = 'text' | 'number' | 'checkbox' | 'date' | 'select' | 'multiselect' | 'textarea'

export interface FieldDef {
  name: string
  label: () => string
  type: FieldType
  required?: boolean
  /** For select/multiselect: a static list or a shared source name. */
  staticOptions?: { value: string | number; label: () => string }[]
  source?: OptionSource
  /** Disable when editing an existing record (e.g. a project's customer). */
  lockedOnEdit?: boolean
  /** A select whose option values are strings (e.g. locale, user type). */
  stringValue?: boolean
  /** A select that offers only active options (hides deactivated users), while
   *  keeping whatever is already assigned so an edit doesn't silently drop it. */
  activeOnly?: boolean
}

export type FormValues = Record<string, string | number | boolean | number[]>

export interface EntityDescriptor {
  key: string
  title: () => string
  /** GET list endpoint and the row-wrap key (e.g. 'user' for {user:{…}}). */
  listEndpoint: string
  rowKey: string
  saveEndpoint: string
  deleteEndpoint: string
  columns: ColumnDef[]
  fields: FieldDef[]
  /** A human label for a row, used in the delete confirmation. */
  rowLabel: (row: Record<string, unknown>) => string
  /** Map a list row's inner object to initial form values (add → {}). */
  toForm: (row: Record<string, unknown> | null) => FormValues
  /** Build the JSON save payload from the submitted form values. */
  toPayload: (values: FormValues) => Record<string, unknown>
  /**
   * When false, existing rows cannot be edited — no inline cell editing and no
   * per-row Edit button (e.g. immutable, create-and-delete-only entities).
   * Adding and deleting still apply. Defaults to true.
   */
  editable?: boolean
  /**
   * Build the delete payload from a list row. Defaults to `{ id: row.id }`;
   * override for entities keyed by something other than a numeric id.
   */
  deletePayload?: (row: Record<string, unknown>) => Record<string, unknown>
}

/** Resolves an OptionSource to its loaded options (for column renderers). */
export type OptionLookup = (source: OptionSource) => NamedOption[]
