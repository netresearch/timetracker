import { m } from '../paraglide/messages.js'
import type { EntityDescriptor, OptionLookup, OptionSource } from './types'

type Row = Record<string, unknown>

function mark(value: unknown): string {
  return value ? '✓' : '—'
}

/** id→name via a shared option source, for relation grid columns. */
function rel(source: OptionSource) {
  return (row: Row, key: string, options: OptionLookup): string => {
    const id = Number(row[key] ?? 0)

    return id > 0 ? (options(source).find((o) => o.id === id)?.label ?? String(id)) : ''
  }
}

/** Read the first present key (handles Base::toArray camel+snake duplication). */
function pick(row: Row, ...keys: string[]): unknown {
  for (const key of keys) {
    if (row[key] !== undefined && row[key] !== null) {
      return row[key]
    }
  }

  return undefined
}

const num = (v: unknown): number => Number(v ?? 0)
const str = (v: unknown): string => (v === undefined || v === null ? '' : String(v))
const bool = (v: unknown): boolean => Boolean(v)

export function adminEntities(): EntityDescriptor[] {
  return [
    {
      key: 'customers',
      title: () => m.admin_e_customers(),
      listEndpoint: '/getAllCustomers',
      rowKey: 'customer',
      saveEndpoint: '/customer/save',
      deleteEndpoint: '/customer/delete',
      columns: [
        { key: 'name', label: () => m.admin_f_name() },
        { key: 'teams', label: () => m.admin_f_teams(), render: (row, o) => ((row.teams as number[]) ?? []).map((id) => o('teams').find((t) => t.id === id)?.label ?? id).join(', ') },
        { key: 'active', label: () => m.admin_f_active(), render: (row) => mark(row.active), align: 'center' },
        { key: 'global', label: () => m.admin_f_global(), render: (row) => mark(row.global), align: 'center' },
      ],
      fields: [
        { name: 'name', label: () => m.admin_f_name(), type: 'text', required: true },
        { name: 'active', label: () => m.admin_f_active(), type: 'checkbox' },
        { name: 'global', label: () => m.admin_f_global(), type: 'checkbox' },
        { name: 'teams', label: () => m.admin_f_teams(), type: 'multiselect', source: 'teams' },
      ],
      rowLabel: (row) => str(row.name),
      toForm: (row) => row === null
        ? { id: 0, name: '', active: true, global: false, teams: [] }
        : { id: num(row.id), name: str(row.name), active: bool(row.active), global: bool(row.global), teams: (row.teams as number[]) ?? [] },
      toPayload: (v) => ({ id: v.id, name: v.name, active: v.active, global: v.global, teams: v.teams }),
    },
    {
      key: 'projects',
      title: () => m.admin_e_projects(),
      listEndpoint: '/getAllProjects',
      rowKey: 'project',
      saveEndpoint: '/project/save',
      deleteEndpoint: '/project/delete',
      columns: [
        { key: 'name', label: () => m.admin_f_name() },
        { key: 'customer', label: () => m.admin_f_customer(), render: (row, o) => rel('customers')(row, 'customer', o) },
        { key: 'jiraId', label: () => m.admin_f_jira_id() },
        { key: 'active', label: () => m.admin_f_active(), render: (row) => mark(row.active), align: 'center' },
        { key: 'global', label: () => m.admin_f_global(), render: (row) => mark(row.global), align: 'center' },
      ],
      fields: [
        { name: 'name', label: () => m.admin_f_name(), type: 'text', required: true },
        { name: 'customer', label: () => m.admin_f_customer(), type: 'select', source: 'customers', lockedOnEdit: true },
        { name: 'ticket_system', label: () => m.admin_f_ticket_system(), type: 'select', source: 'ticketSystems' },
        { name: 'jiraId', label: () => m.admin_f_jira_id(), type: 'text' },
        { name: 'jiraTicket', label: () => m.admin_f_jira_ticket(), type: 'text' },
        { name: 'additionalInformationFromExternal', label: () => m.admin_f_ext_info(), type: 'checkbox' },
        { name: 'active', label: () => m.admin_f_active(), type: 'checkbox' },
        { name: 'global', label: () => m.admin_f_global(), type: 'checkbox' },
        { name: 'project_lead', label: () => m.admin_f_project_lead(), type: 'select', source: 'users' },
        { name: 'technical_lead', label: () => m.admin_f_technical_lead(), type: 'select', source: 'users' },
        { name: 'offer', label: () => m.admin_f_offer(), type: 'text' },
        { name: 'cost_center', label: () => m.admin_f_cost_center(), type: 'text' },
        {
          name: 'billing', label: () => m.admin_f_billing(), type: 'select',
          staticOptions: [
            { value: 0, label: () => m.admin_billing_none() },
            { value: 1, label: () => m.admin_billing_tm() },
            { value: 2, label: () => m.admin_billing_fp() },
          ],
        },
        { name: 'estimation', label: () => m.admin_f_estimation(), type: 'text' },
        { name: 'internalJiraProjectKey', label: () => m.admin_f_internal_jira_key(), type: 'text' },
        { name: 'internalJiraTicketSystem', label: () => m.admin_f_internal_jira_system(), type: 'select', source: 'ticketSystems' },
      ],
      rowLabel: (row) => str(row.name),
      toForm: (row) => row === null
        ? { id: 0, name: '', customer: 0, ticket_system: 0, jiraId: '', jiraTicket: '', additionalInformationFromExternal: false, active: true, global: false, project_lead: 0, technical_lead: 0, offer: '', cost_center: '', billing: 0, estimation: '', internalJiraProjectKey: '', internalJiraTicketSystem: 0 }
        : {
            id: num(row.id),
            name: str(row.name),
            customer: num(pick(row, 'customer')),
            ticket_system: num(pick(row, 'ticket_system', 'ticketSystem')),
            jiraId: str(pick(row, 'jiraId', 'jira_id')),
            jiraTicket: str(pick(row, 'jiraTicket', 'jira_ticket')),
            additionalInformationFromExternal: bool(pick(row, 'additionalInformationFromExternal', 'additional_information_from_external')),
            active: bool(row.active),
            global: bool(row.global),
            project_lead: num(pick(row, 'project_lead', 'projectLead')),
            technical_lead: num(pick(row, 'technical_lead', 'technicalLead')),
            offer: str(row.offer),
            cost_center: str(pick(row, 'cost_center', 'costCenter')),
            billing: num(row.billing),
            estimation: str(pick(row, 'estimationText', 'estimation')),
            internalJiraProjectKey: str(pick(row, 'internalJiraProjectKey', 'internal_jira_project_key')),
            internalJiraTicketSystem: num(pick(row, 'internalJiraTicketSystem', 'internal_jira_ticket_system')),
          },
      toPayload: (v) => ({ ...v }),
    },
    {
      key: 'users',
      title: () => m.admin_e_users(),
      listEndpoint: '/getAllUsers',
      rowKey: 'user',
      saveEndpoint: '/user/save',
      deleteEndpoint: '/user/delete',
      columns: [
        { key: 'username', label: () => m.admin_f_username() },
        { key: 'abbr', label: () => m.admin_f_abbr() },
        { key: 'type', label: () => m.admin_f_type() },
        { key: 'teams', label: () => m.admin_f_teams(), render: (row, o) => ((row.teams as number[]) ?? []).map((id) => o('teams').find((t) => t.id === id)?.label ?? id).join(', ') },
      ],
      fields: [
        { name: 'username', label: () => m.admin_f_username(), type: 'text', required: true },
        { name: 'abbr', label: () => m.admin_f_abbr(), type: 'text', required: true },
        {
          name: 'locale', label: () => m.admin_f_language(), type: 'select', stringValue: true,
          staticOptions: [
            { value: 'de', label: () => 'Deutsch' }, { value: 'en', label: () => 'English' },
            { value: 'es', label: () => 'Español' }, { value: 'fr', label: () => 'Français' },
            { value: 'ru', label: () => 'Русский' },
          ],
        },
        {
          name: 'type', label: () => m.admin_f_type(), type: 'select', stringValue: true,
          staticOptions: [
            { value: 'DEV', label: () => m.admin_type_dev() },
            { value: 'PL', label: () => m.admin_type_pl() },
            { value: 'CTL', label: () => m.admin_type_ctl() },
          ],
        },
        { name: 'teams', label: () => m.admin_f_teams(), type: 'multiselect', source: 'teams', required: true },
      ],
      rowLabel: (row) => str(row.username),
      toForm: (row) => row === null
        ? { id: 0, username: '', abbr: '', locale: 'de', type: 'DEV', teams: [] }
        : { id: num(row.id), username: str(row.username), abbr: str(row.abbr), locale: str(row.locale) || 'de', type: str(row.type) || 'DEV', teams: (row.teams as number[]) ?? [] },
      // locale/type are string selects; the shell stores select values as numbers,
      // so re-stringify here before sending.
      toPayload: (v) => ({ id: v.id, username: v.username, abbr: v.abbr, locale: v.locale, type: v.type, teams: v.teams }),
    },
    {
      key: 'teams',
      title: () => m.admin_e_teams(),
      listEndpoint: '/getAllTeams',
      rowKey: 'team',
      saveEndpoint: '/team/save',
      deleteEndpoint: '/team/delete',
      columns: [
        { key: 'name', label: () => m.admin_f_name() },
        { key: 'lead_user_id', label: () => m.admin_f_team_lead(), render: (row, o) => rel('users')(row, 'lead_user_id', o) },
      ],
      fields: [
        { name: 'name', label: () => m.admin_f_name(), type: 'text', required: true },
        { name: 'lead_user_id', label: () => m.admin_f_team_lead(), type: 'select', source: 'users', required: true },
      ],
      rowLabel: (row) => str(row.name),
      toForm: (row) => row === null
        ? { id: 0, name: '', lead_user_id: 0 }
        : { id: num(row.id), name: str(row.name), lead_user_id: num(row.lead_user_id) },
      toPayload: (v) => ({ id: v.id, name: v.name, lead_user_id: v.lead_user_id }),
    },
    {
      key: 'presets',
      title: () => m.admin_e_presets(),
      listEndpoint: '/getAllPresets',
      rowKey: 'preset',
      saveEndpoint: '/preset/save',
      deleteEndpoint: '/preset/delete',
      columns: [
        { key: 'name', label: () => m.admin_f_name() },
        { key: 'customer', label: () => m.admin_f_customer(), render: (row, o) => rel('customers')(row, 'customer', o) },
        { key: 'project', label: () => m.admin_f_project(), render: (row, o) => rel('projects')(row, 'project', o) },
        { key: 'activity', label: () => m.admin_f_activity(), render: (row, o) => rel('activities')(row, 'activity', o) },
        { key: 'description', label: () => m.admin_f_description() },
      ],
      fields: [
        { name: 'name', label: () => m.admin_f_name(), type: 'text', required: true },
        { name: 'customer', label: () => m.admin_f_customer(), type: 'select', source: 'customers', required: true },
        { name: 'project', label: () => m.admin_f_project(), type: 'select', source: 'projects', required: true },
        { name: 'activity', label: () => m.admin_f_activity(), type: 'select', source: 'activities', required: true },
        { name: 'description', label: () => m.admin_f_description(), type: 'text' },
      ],
      rowLabel: (row) => str(row.name),
      toForm: (row) => row === null
        ? { id: 0, name: '', customer: 0, project: 0, activity: 0, description: '' }
        : { id: num(row.id), name: str(row.name), customer: num(row.customer), project: num(row.project), activity: num(row.activity), description: str(row.description) },
      toPayload: (v) => ({ ...v }),
    },
    {
      key: 'ticketsystems',
      title: () => m.admin_e_ticketsystems(),
      listEndpoint: '/getTicketSystems',
      rowKey: 'ticketSystem',
      saveEndpoint: '/ticketsystem/save',
      deleteEndpoint: '/ticketsystem/delete',
      columns: [
        { key: 'name', label: () => m.admin_f_name() },
        { key: 'type', label: () => m.admin_f_type() },
        { key: 'bookTime', label: () => m.admin_f_book_time(), render: (row) => mark(pick(row, 'bookTime', 'book_time')), align: 'center' },
        { key: 'url', label: () => m.admin_f_url() },
      ],
      fields: [
        { name: 'name', label: () => m.admin_f_name(), type: 'text', required: true },
        {
          name: 'type', label: () => m.admin_f_type(), type: 'select', stringValue: true,
          staticOptions: [
            { value: 'JIRA', label: () => 'JIRA' }, { value: 'OTRS', label: () => 'OTRS' }, { value: 'FRESHDESK', label: () => 'FRESHDESK' },
          ],
        },
        { name: 'bookTime', label: () => m.admin_f_book_time(), type: 'checkbox' },
        { name: 'url', label: () => m.admin_f_url(), type: 'text' },
        { name: 'ticketUrl', label: () => m.admin_f_ticket_url(), type: 'text' },
        { name: 'login', label: () => m.admin_f_login(), type: 'text' },
        { name: 'password', label: () => m.admin_f_password(), type: 'text' },
        { name: 'publicKey', label: () => m.admin_f_public_key(), type: 'textarea' },
        { name: 'privateKey', label: () => m.admin_f_private_key(), type: 'textarea' },
        { name: 'oauthConsumerKey', label: () => m.admin_f_oauth_key(), type: 'text' },
        { name: 'oauthConsumerSecret', label: () => m.admin_f_oauth_secret(), type: 'textarea' },
      ],
      rowLabel: (row) => str(row.name),
      // Credentials are not returned by the list (server-side filtered), so the
      // form opens them blank; the backend keeps the stored value when a field
      // is submitted blank (see SaveTicketSystemAction preserve-on-blank).
      toForm: (row) => row === null
        ? { id: 0, name: '', type: 'JIRA', bookTime: false, url: '', ticketUrl: '', login: '', password: '', publicKey: '', privateKey: '', oauthConsumerKey: '', oauthConsumerSecret: '' }
        : {
            id: num(row.id), name: str(row.name), type: str(pick(row, 'type')) || 'JIRA',
            bookTime: bool(pick(row, 'bookTime', 'book_time')),
            url: str(row.url), ticketUrl: str(pick(row, 'ticketUrl', 'ticket_url')),
            login: str(row.login), password: '', publicKey: '', privateKey: '',
            oauthConsumerKey: '', oauthConsumerSecret: '',
          },
      toPayload: (v) => ({ ...v }),
    },
    {
      key: 'activities',
      title: () => m.admin_e_activities(),
      listEndpoint: '/getActivities',
      rowKey: 'activity',
      saveEndpoint: '/activity/save',
      deleteEndpoint: '/activity/delete',
      columns: [
        { key: 'name', label: () => m.admin_f_name() },
        { key: 'needsTicket', label: () => m.admin_f_needs_ticket(), render: (row) => mark(pick(row, 'needsTicket', 'needs_ticket')), align: 'center' },
        { key: 'factor', label: () => m.admin_f_factor(), align: 'right' },
      ],
      fields: [
        { name: 'name', label: () => m.admin_f_name(), type: 'text', required: true },
        { name: 'needsTicket', label: () => m.admin_f_needs_ticket(), type: 'checkbox' },
        { name: 'factor', label: () => m.admin_f_factor(), type: 'number' },
      ],
      rowLabel: (row) => str(row.name),
      toForm: (row) => row === null
        ? { id: 0, name: '', needsTicket: false, factor: 1 }
        : { id: num(row.id), name: str(row.name), needsTicket: bool(pick(row, 'needsTicket', 'needs_ticket')), factor: num(row.factor) },
      toPayload: (v) => ({ ...v }),
    },
    {
      key: 'contracts',
      title: () => m.admin_e_contracts(),
      listEndpoint: '/getContracts',
      rowKey: 'contract',
      saveEndpoint: '/contract/save',
      deleteEndpoint: '/contract/delete',
      columns: [
        { key: 'user_id', label: () => m.admin_f_username(), render: (row, o) => rel('users')(row, 'user_id', o) },
        { key: 'start', label: () => m.admin_f_start() },
        { key: 'end', label: () => m.admin_f_end() },
      ],
      fields: [
        { name: 'user_id', label: () => m.admin_f_username(), type: 'select', source: 'users', required: true },
        { name: 'start', label: () => m.admin_f_start(), type: 'date', required: true },
        { name: 'end', label: () => m.admin_f_end(), type: 'date' },
        { name: 'hours_1', label: () => m.admin_day_1(), type: 'number' },
        { name: 'hours_2', label: () => m.admin_day_2(), type: 'number' },
        { name: 'hours_3', label: () => m.admin_day_3(), type: 'number' },
        { name: 'hours_4', label: () => m.admin_day_4(), type: 'number' },
        { name: 'hours_5', label: () => m.admin_day_5(), type: 'number' },
        { name: 'hours_6', label: () => m.admin_day_6(), type: 'number' },
        { name: 'hours_0', label: () => m.admin_day_0(), type: 'number' },
      ],
      rowLabel: (row) => str(row.start),
      toForm: (row) => row === null
        ? { id: 0, user_id: 0, start: '', end: '', hours_1: 8, hours_2: 8, hours_3: 8, hours_4: 8, hours_5: 8, hours_6: 0, hours_0: 0 }
        : {
            id: num(row.id), user_id: num(row.user_id), start: str(row.start), end: str(row.end),
            hours_1: num(row.hours_1), hours_2: num(row.hours_2), hours_3: num(row.hours_3),
            hours_4: num(row.hours_4), hours_5: num(row.hours_5), hours_6: num(row.hours_6), hours_0: num(row.hours_0),
          },
      toPayload: (v) => ({ ...v }),
    },
  ]
}
