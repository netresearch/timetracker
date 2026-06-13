import type { NamedOption } from '../api/queries'

/** Named, shared dropdown sources an admin form field can reference. */
export type OptionSource = 'customers' | 'projects' | 'users' | 'teams' | 'ticketSystems' | 'activities'

export interface ColumnDef {
  key: string
  label: () => string
  /** Render a cell from the raw row object (e.g. id→name, bool→✓). */
  render?: (row: Record<string, unknown>, options: OptionLookup) => string
  /** Cell/header alignment. Numbers → 'right', booleans (✓/—) → 'center'. */
  align?: 'left' | 'right' | 'center'
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
}

/** Resolves an OptionSource to its loaded options (for column renderers). */
export type OptionLookup = (source: OptionSource) => NamedOption[]
