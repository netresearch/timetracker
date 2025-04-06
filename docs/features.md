# Netresearch TimeTracker Features

This document outlines the key features of the Netresearch TimeTracker application.

## Core Time Tracking

*   **Time Entry:** Add, edit, and delete work log entries easily.
*   **Autocompletion:** Smart suggestions for project and task details during entry.
*   **Bulk Entry:** Quickly log time for extended periods like sickness or vacation using presets (requires configuration by Project Leader).
*   **Keyboard Shortcuts:** Efficient navigation and actions (e.g., 'a' to add, 'd' to delete, arrow keys to focus).

## Reporting & Statistics

*   **Charts:** Visualize tracked time with bar charts available per user, per project, and company-wide in the "Interpretation" tab.
*   **Controlling Export:** Export time tracking data to XLSX format for analysis and billing (available to Controller and Project Leader roles).
*   **External Statistics:** Integration point for advanced statistics via external tools like [Timalytics](https://github.com/netresearch/timalytics) (requires separate setup and configuration via `APP_MONTHLY_OVERVIEW_URL`).

## Administration

*(Requires Project Leader role)*

*   **User Management:** Create, edit, and manage user accounts and roles.
*   **Customer Management:** Define and manage clients.
*   **Project Management:** Create projects, assign them to customers, and manage project details.
*   **Team Management:** Organize users into teams.
*   **Presets:** Configure presets for bulk time entries (e.g., vacation, sickness).
*   **Activity Management:** Define standard activities or task types.
*   **Ticket System Configuration:** Set up integrations with external ticket systems like Jira.

## Integrations

*   **LDAP / Active Directory Authentication:** Authenticate users against an existing LDAP or Active Directory server.
    *   **Automatic User Creation:** Optionally, automatically create TimeTracker user accounts (with DEV role) upon the first successful LDAP login (`LDAP_CREATE_USER` setting).
*   **Jira Integration:**
    *   **Work Log Synchronization:** Automatically create and update work log entries in linked Jira issues based on TimeTracker entries.
    *   **OAuth Authentication:** Securely connect TimeTracker to Jira using OAuth 1.0a (requires setup in both Jira and TimeTracker).

## User Roles & Permissions

The application defines several user roles with increasing levels of access:

*   **DEV (Developer):**
    *   Track time for assigned projects.
    *   Use bulk entry presets.
    *   View personal and project charts.
*   **CTL (Controller):**
    *   Includes all DEV permissions.
    *   Export time data (XLSX) from the "Controlling" tab.
*   **PL (Project Leader):**
    *   Includes all CTL permissions.
    *   Full access to the "Administration" tab (manage users, customers, projects, teams, presets, ticket systems, activities).

## Service Users

*   Specific users can be designated as "Service Users" (`SERVICE_USERS` setting).
*   These users can make API calls on behalf of other users, useful for integrations or administrative tasks.
