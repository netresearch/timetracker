# Application Features

This document provides a comprehensive overview of the features available in the TimeTracker application.

## Core Time Tracking

*   **Time Entry:** Record work hours against specific projects, customers, and activities.
*   **Autocompletion:** Suggestions for project, customer, and activity fields during entry.
*   **Editing:** Modify existing time entries directly in the log view.
*   **Deletion:** Remove time entries via context menu or keyboard shortcuts.
*   **Bulk Entry:** Efficiently log time for common scenarios like sickness or vacation using pre-defined presets.
*   **Keyboard Shortcuts:** Navigate and perform actions (add, delete, focus) using keyboard shortcuts for faster interaction.

## Reporting & Analysis

*   **Visualizations:** View time data through various charts:
    *   Per-user charts.
    *   Per-project charts.
    *   Company-wide overview charts.
*   **Controlling Export:** Export time tracking data to XLSX format for analysis and controlling purposes.
    *   Optionally include a "billable" field in the export (configurable via `.env`).
*   **External Statistics:** Link to an external statistics tool (like Timalytics) for more advanced analysis (configurable via `.env`).

## Administration & Management

*   **User Management:** Create, edit, and manage user accounts.
*   **Customer Management:** Define and manage client or customer records.
*   **Project Management:** Create and manage projects, associating them with customers and ticket systems.
*   **Team Management:** Organize users into teams.
*   **Activity Management:** Define standard activities used for time tracking.
*   **Preset Management:** Configure presets for bulk time entries (e.g., "Vacation", "Sick Day").
*   **Ticket System Management:** Configure connections to external ticket systems (e.g., Jira).

## User Roles & Permissions

The application uses a role-based access control system:

*   **DEV (Developer):** Basic user role. Can track time, use bulk entry, and view standard reports.
*   **CTL (Controller):** Includes DEV permissions. Can also export data via the Controlling tab.
*   **PL (Project Leader):** Includes CTL permissions. Can also manage administrative entities (Customers, Projects, Users, Teams, Presets, Ticket Systems, Activities).

## Authentication & Security

*   **LDAP / Active Directory Authentication:** Authenticate users against an external LDAP or Active Directory server.
    *   Optional automatic user creation in TimeTracker upon successful first LDAP login (configurable via `.env`).
*   **Standard Login:** (Implied - fallback if LDAP is not used/configured).
*   **CSRF Protection:** Standard Symfony security feature.

## Integrations

*   **Jira Integration:**
    *   **Work Log Synchronization:** Automatically create and update work log entries in linked Jira issues based on TimeTracker entries (requires OAuth configuration).
    *   **Internal Ticket Mapping:** Track time against external ticket numbers (e.g., from a client's Jira) and automatically create/link corresponding issues in an internal Jira project.
    *   **(Optional) Jira Cloud Time Display:** Use a Greasemonkey script to fetch and display TimeTracker times directly within the Jira Cloud interface.
*   **Sentry Integration:** Report application errors and exceptions to Sentry for monitoring (configurable via DSN in `.env` or `sentry.yaml`).

## API

*   **RESTful API:** Provides programmatic access to TimeTracker data and functionality.
*   **OpenAPI Documentation:** API is documented using OpenAPI v3 specification (`public/api.yml`), viewable via Swagger UI at `/docs/swagger/index.html`.
*   **Service User Impersonation:** Designated service users can perform API actions on behalf of other users (configured via `SERVICE_USERS` in `.env`).

## User Interface

*   **Web-Based Interface:** Accessible via a standard web browser.
*   **Configurable Branding:** Application title and logo can be customized via `.env` variables.
