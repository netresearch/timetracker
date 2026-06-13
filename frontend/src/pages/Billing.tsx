import { useQuery } from '@tanstack/solid-query'
import { createMemo, createSignal, For, Show } from 'solid-js'

import { customersQuery, projectsQuery, usersQuery, type NamedOption } from '../api/queries'
import { appConfig } from '../config'
import { m } from '../paraglide/messages.js'

const MONTH_VALUES = Array.from({ length: 12 }, (_, i) => i + 1)

/** Builds the same-origin export URL; the browser handles the XLSX download. */
function exportHref(params: Record<string, string | number>): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    query.set(key, String(value))
  }

  return `/controlling/export?${query.toString()}`
}

function OptionSelect(props: {
  label: string
  value: number
  onInput: (value: number) => void
  options: NamedOption[] | undefined
  loading: boolean
}) {
  return (
    <label class="field">
      <span>{props.label}</span>
      <select
        disabled={props.loading}
        value={props.value}
        onInput={(event) => props.onInput(Number(event.currentTarget.value))}
      >
        <option value="0">{m.billing_all()}</option>
        <For each={props.options ?? []}>
          {(option) => <option value={option.id}>{option.label}</option>}
        </For>
      </select>
    </label>
  )
}

export default function Billing() {
  const config = appConfig()
  const now = new Date()
  // Default to the previous month; in January that is December of last year.
  const isJanuary = now.getMonth() === 0
  const defaultYear = isJanuary ? now.getFullYear() - 1 : now.getFullYear()
  const defaultMonth = isJanuary ? 12 : now.getMonth() // previous month, 1-based

  const [user, setUser] = createSignal(0)
  const [project, setProject] = createSignal(0)
  const [customer, setCustomer] = createSignal(0)
  const [year, setYear] = createSignal(defaultYear)
  const [month, setMonth] = createSignal(defaultMonth)
  const [billable, setBillable] = createSignal(false)
  const [ticketTitles, setTicketTitles] = createSignal(false)

  const users = useQuery(usersQuery)
  const projects = useQuery(projectsQuery)
  const customers = useQuery(customersQuery)

  const years = Array.from({ length: 5 }, (_, i) => now.getFullYear() - i)

  // Use the 15th so a timezone offset can't shift the label to another month.
  const monthOptions = createMemo(() => {
    const formatter = new Intl.DateTimeFormat(config.locale, { month: 'long' })

    return MONTH_VALUES.map((value) => ({ value, label: formatter.format(new Date(2000, value - 1, 15)) }))
  })

  const href = createMemo(() =>
    exportHref({
      userid: user(),
      year: year(),
      month: month(),
      project: project(),
      customer: customer(),
      billable: billable() ? 1 : 0,
      tickettitles: ticketTitles() ? 1 : 0,
    }),
  )

  return (
    <section class="form-page">
      <h2>{m.billing_title()}</h2>
      <Show when={users.isError || projects.isError || customers.isError}>
        <p role="alert">{m.app_load_error()}</p>
      </Show>
      <form class="stack-form" onSubmit={(event) => event.preventDefault()}>
        <OptionSelect
          label={m.billing_user()}
          value={user()}
          onInput={setUser}
          options={users.data}
          loading={users.isPending}
        />
        <OptionSelect
          label={m.billing_project()}
          value={project()}
          onInput={setProject}
          options={projects.data}
          loading={projects.isPending}
        />
        <OptionSelect
          label={m.billing_customer()}
          value={customer()}
          onInput={setCustomer}
          options={customers.data}
          loading={customers.isPending}
        />

        <label class="field">
          <span>{m.billing_year()}</span>
          <select value={year()} onInput={(event) => setYear(Number(event.currentTarget.value))}>
            <For each={years}>
              {(value) => <option value={value}>{value}</option>}
            </For>
          </select>
        </label>

        <label class="field">
          <span>{m.billing_month()}</span>
          <select value={month()} onInput={(event) => setMonth(Number(event.currentTarget.value))}>
            <For each={monthOptions()}>
              {(option) => <option value={option.value}>{option.label}</option>}
            </For>
          </select>
        </label>

        <label class="field-check">
          <input type="checkbox" checked={billable()} onInput={(e) => setBillable(e.currentTarget.checked)} />
          <span>{m.billing_billable_only()}</span>
        </label>
        <label class="field-check">
          <input type="checkbox" checked={ticketTitles()} onInput={(e) => setTicketTitles(e.currentTarget.checked)} />
          <span>{m.billing_ticket_titles()}</span>
        </label>

        <div class="form-actions">
          {/* A real navigation (anchor with download), not fetch, so the
              browser saves the attachment from the XLSX response. */}
          <a class="primary-button" href={href()} download="">
            {m.billing_export()}
          </a>
        </div>
      </form>
    </section>
  )
}
