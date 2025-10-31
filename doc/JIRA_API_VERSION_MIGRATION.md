# Jira API Version Configuration

## Overview

This feature adds support for configurable Jira API versions, allowing TimeTracker to work with both:
- **Jira Server/On-Prem 9.x** (using API v2)
- **Jira Cloud** (using API v3)

## Background

Previously, TimeTracker hardcoded the API endpoint to `/rest/api/latest/`, which:
- Works for Jira Server (where `latest` points to API v2)
- May have compatibility issues with Jira Cloud (which uses API v3)

## Changes Made

### Database Schema

A new field `jira_api_version` has been added to the `ticket_systems` table:
- **Type**: VARCHAR(10)
- **Default**: '2' (for backward compatibility)
- **Values**: '2' for Jira Server/On-Prem, '3' for Jira Cloud

### Migration

For existing installations, run the migration script:

```sql
-- Run this migration
source sql/005_jira_api_version.sql;
```

Or manually execute:

```sql
ALTER TABLE `ticket_systems` ADD COLUMN `jira_api_version` VARCHAR(10) NULL DEFAULT '2' AFTER `oauth_consumer_secret`;
```

For fresh installations, the field is already included in `sql/full.sql`.

### Backend Changes

1. **TicketSystem Entity** (`src/Netresearch/TimeTrackerBundle/Entity/TicketSystem.php`)
   - Added `jiraApiVersion` property with getter and setter
   - Default value is '2' for backward compatibility

2. **JiraOAuthApi** (`src/Netresearch/TimeTrackerBundle/Helper/JiraOAuthApi.php`)
   - Changed from hardcoded `/rest/api/latest/` to configurable `/rest/api/{version}/`
   - API version is determined from the ticket system configuration

3. **AdminController** (`src/Netresearch/TimeTrackerBundle/Controller/AdminController.php`)
   - Updated to handle `jiraApiVersion` parameter when saving ticket systems

### Frontend Changes

1. **TicketSystem Model** (`Resources/public/js/netresearch/model/TicketSystem.js`)
   - Added `jiraApiVersion` field

2. **Admin Widget** (`Resources/public/js/netresearch/widget/Admin.js`)
   - Added dropdown selector for API version in ticket system edit form
   - Options: '2 (Jira Server/On-Prem)' or '3 (Jira Cloud)'
   - Added API version column to the ticket systems grid
   - Added translations for English and German

## Usage

### For New Ticket Systems

1. Navigate to **Administration > Ticket-System**
2. Click **Add ticket system**
3. Fill in the required fields
4. Select the appropriate **JIRA API version**:
   - Choose **2** for Jira Server/Data Center/On-Prem 9.x
   - Choose **3** for Jira Cloud
5. Save the ticket system

### For Existing Ticket Systems

1. Navigate to **Administration > Ticket-System**
2. Edit an existing Jira ticket system
3. Select the appropriate **JIRA API version** from the dropdown
4. Save the changes

**Note**: Existing ticket systems will default to API version 2 to maintain compatibility with Jira Server installations.

### Updating the Database Manually

If you need to update an existing ticket system via SQL:

```sql
-- Update to use Jira Cloud API v3
UPDATE ticket_systems SET jira_api_version = '3' WHERE id = <your_ticket_system_id>;

-- Update to use Jira Server API v2
UPDATE ticket_systems SET jira_api_version = '2' WHERE id = <your_ticket_system_id>;
```

## API Endpoint Construction

The API URL is now constructed as follows:

```
{base_url}/rest/api/{jira_api_version}/
```

Examples:
- Jira Server: `https://jira.example.com/rest/api/2/`
- Jira Cloud: `https://yourcompany.atlassian.net/rest/api/3/`

## Backward Compatibility

- All existing ticket systems will default to API version **2**
- No manual intervention is required for existing Jira Server installations
- The migration is fully backward compatible

## Testing

To verify the configuration is working:

1. Configure a ticket system with the appropriate API version
2. Link the ticket system to a project
3. Create a time entry with a valid ticket number
4. Verify that the worklog is created/updated in Jira
5. Check for any authentication or API errors

## Troubleshooting

### Common Issues

1. **401 Unauthorized errors**
   - Verify OAuth tokens are configured correctly
   - Re-authorize the TimeTracker application in Jira

2. **404 Not Found errors**
   - Verify the API version matches your Jira installation type
   - Check that the base URL is correct

3. **Worklog not created**
   - Ensure the ticket exists in Jira
   - Verify the user has permission to add worklogs
   - Check the Jira API version setting

### API Version Selection Guide

| Jira Type | Version | API Version to Use |
|-----------|---------|-------------------|
| Jira Server 9.x | Self-hosted | **2** |
| Jira Data Center | Self-hosted | **2** |
| Jira Cloud | Atlassian-hosted | **3** |

## References

- [Jira Server 9.x REST API Documentation](https://docs.atlassian.com/software/jira/docs/api/REST/9.0.0/)
- [Jira Cloud REST API Documentation](https://developer.atlassian.com/cloud/jira/platform/rest/v3/)
- [Jira API Migration Guide](https://developer.atlassian.com/cloud/jira/platform/deprecation-notice-user-privacy-api-migration-guide/)
