# TimeTracker FAQ

Answers to common questions about using TimeTracker. For a full walkthrough
of every page see the [User Guide](user-guide.md).

## General

### What is TimeTracker?

A web application for logging work time against customers, projects and
activities, with reporting, an XLSX controlling export and optional Jira
worklog synchronization. See [features.md](features.md) for the full list.

### Which user roles exist?

| Type | Access |
|---|---|
| **USER** / **DEV** | Track time, bulk entry, Overview, Evaluation, own CSV export |
| **PL** (Project Lead) | All of the above plus Billing and Administration |
| **ADMIN** | All of the above plus Billing and Administration |

PL currently carries full admin rights (a v4 compatibility carry-over), so PL
and ADMIN unlock the same pages. Defined in
[`src/Enum/UserType.php`](../src/Enum/UserType.php).

### Which languages does the UI support?

English and German. Change it under ⚙ Settings → Language.

## Logging in

### How do I log in?

With your organization (LDAP / Active Directory) username and password on the
login page. *Stay signed in* keeps the session for 30 days on that browser.

### I can't log in — what should I check?

- **Wrong credentials:** the login checks your directory (LDAP/AD) password —
  the same one you use for other company systems. TimeTracker itself cannot
  reset it; use your organization's usual password process.
- **"This account has been deactivated":** an administrator has disabled your
  account (Administration → Users → Active). Ask an admin to re-activate it.
- **First-ever login fails although the password is right:** if the instance
  runs with automatic user creation disabled (`LDAP_CREATE_USER=false`), an
  administrator must create your account before you can log in.

### Why did a "Session expired" dialog appear while I was working?

Your server session ended (e.g. after a long idle period). Sign in again in
the dialog — the page underneath is kept, including unsaved changes in the
Worklog grid.

## Time tracking

### How do I add an entry?

Press <kbd>Alt</kbd>+<kbd>A</kbd> or the **+** button in the Worklog. Fill in
date, start/end time, customer, project and activity (plus ticket and
description) — the row saves automatically once it's complete. Details:
[User Guide → Worklog](user-guide.md#worklog--tracking-your-time).

### How do I edit or delete an entry?

Double-click a cell (or focus it and press <kbd>Enter</kbd>/<kbd>F2</kbd>)
to edit in place. Delete via the trash icon in the row — a confirmation
dialog follows. There is no context menu.

### What time formats can I type?

Times are start/end pairs, parsed flexibly: `9:30`, `09:30`, `930`, `0930`,
`9.30`, `9h30`, plain `9` (→ 09:00), and am/pm suffixes like `9:30am` or `9p`.

### Why is my entry colored?

Row colors relate an entry to the one above it: **day break** (first entry of
a day), **break** (an unbooked gap before it), **time overlap** (it starts
before the previous entry ended). The legend is on the in-app Help page.

### How do I book a whole vacation or sick-leave period?

Use **Bulk entry** in the Worklog toolbar: choose a preset (e.g. "Vacation"),
a date range, and whether to use your contract hours or fixed times; weekends
and holidays are skipped by default. Presets are maintained by administrators
under Administration → Presets.

### Can I enter time for past or future dates?

Yes — set the entry's date as needed. Future-dated entries are shown in the
grid only when *Show future* is enabled in Settings.

## Working-time targets (contracts)

### Where do the "expected" times on the Overview come from?

From your **work contract**: an administrator records target hours for each
weekday plus a validity period (Administration → Contracts). The Overview
compares your booked time per day against those hours; weekends and public
holidays (Administration → Holidays) count as 0 expected. The header's
Today/Week/Month badges use the same targets.

### My balance looks wrong — why?

Usually the contract is missing or outdated: without a contract valid for the
month, expected time is 0. Ask an administrator to check your entry under
Administration → Contracts. A contract change mid-month takes effect for the
whole month in which it is valid on the 1st.

## Jira integration

### How does the Jira sync trigger?

Automatically — there is no per-entry sync option. When your project is linked
to a Jira ticket system that has *time booking* enabled, every save, edit or
delete of an entry with a ticket is mirrored as a worklog on that Jira ticket
in the background. Changing an entry's ticket removes the worklog from the old
ticket. A failed sync never blocks saving in TimeTracker.

### How do I connect my Jira account?

There is no manual "connect" page. TimeTracker uses a per-user OAuth token per
ticket system: the first time it needs to act on your behalf, it hands you a
link to Jira's *authorize* page ("Please authorize: …"). Approve there and
Jira returns you to TimeTracker — the token is stored (encrypted) and your
most recent entries are synced.

### Why isn't my time showing up in Jira?

- Your Jira authorization is missing or expired (see the previous answer).
- The ticket system may not have *time booking* enabled, or the project isn't
  linked to it — an administrator can check that under Administration →
  Ticket systems / Projects.
- Administrators can re-push pending worklogs via `GET /syncentries/jira`.

### Can I see TimeTracker times inside Jira?

Yes, with the optional userscript for Jira Cloud — see
[features.md → Jira Cloud Time Display](features.md#jira-cloud-time-display-userscript).

## Reports and exports

### How do I analyse booked time?

Use the **Evaluation** page: pick a date range and at least one filter
(customer, project, team, user, activity, ticket or description) and press
Refresh. You get effort breakdowns by customer, project, ticket, activity,
user and day, plus a sortable entry list.

### How do I export data?

- **Your own entries as CSV:** Worklog toolbar → download icon (or
  <kbd>Alt</kbd>+<kbd>X</kbd>); exports the currently shown day range.
- **Monthly statement as XLSX** (PL/ADMIN): the **Billing** page — filter by
  user/project/customer, pick year and month, and Export.
- **Admin lists as CSV** (PL/ADMIN): every Administration panel has an
  *Export CSV* button for the filtered list.

There is no JSON export.

## Keyboard shortcuts

Press <kbd>?</kbd> for the in-app cheat sheet, or hold <kbd>Alt</kbd> to see
shortcut badges on the buttons themselves. The most important ones
(full tables in the [User Guide](user-guide.md#keyboard-shortcuts), source:
[`frontend/src/lib/shortcuts.ts`](../frontend/src/lib/shortcuts.ts)):

| Keys | Action |
|---|---|
| <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>K</kbd> | Command palette |
| <kbd>Alt</kbd>+<kbd>1</kbd>…<kbd>7</kbd> | Switch navigation tab |
| <kbd>Alt</kbd>+<kbd>A</kbd> | Add entry |
| <kbd>Alt</kbd>+<kbd>C</kbd> / <kbd>P</kbd> / <kbd>I</kbd> | Continue / prolong / info for the current row |
| <kbd>Alt</kbd>+<kbd>R</kbd> / <kbd>X</kbd> | Refresh / export CSV |
| <kbd>Enter</kbd> / <kbd>F2</kbd> | Edit the focused cell |
| <kbd>/</kbd> | Jump to search/filter |
| <kbd>?</kbd> | Shortcut help |

## Settings

### Where are my personal settings?

Behind the ⚙ icon in the header: language, empty-line/suggest-time/show-future
toggles and minimum entry duration are saved to your account; Enter behavior,
date format, font, text size and navigation layout apply instantly and are
stored per device. See [User Guide → Settings](user-guide.md#settings).

### How do I switch to dark mode?

The theme button in the header cycles System → Light → Dark. The density
button next to it cycles Comfortable → Compact → Ultra-compact.

## Projects and customers

### What's the difference between a customer and a project?

A **customer** is the client organization; a **project** is a piece of work
for exactly one customer. A customer can have many projects.

### How do I get a new project or preset added?

Ask a project lead or administrator — both are managed under Administration
(→ Projects, → Presets).

## Troubleshooting & support

### My entry isn't saving

A row only auto-saves once date, start, end, customer, project and activity
are valid — incomplete or invalid fields show an error beneath the row. The
disk icon force-saves and surfaces the full error message. Also check for a
"Session expired" dialog.

### Where do I report bugs or request features?

On GitHub: [issues](https://github.com/netresearch/timetracker/issues) and
[discussions](https://github.com/netresearch/timetracker/discussions). For
instance-specific problems (accounts, contracts, Jira connectivity) contact
your administrator.

### Is there an API?

Yes — the same HTTP API the UI uses, with session-based authentication. The
OpenAPI v3 spec lives at [`public/api.yml`](../public/api.yml) (linked from
the in-app Help page); a Swagger UI is served under `/docs/swagger/` behind
login. See [api.md](api.md).
