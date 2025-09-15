# TimeTracker FAQ (Frequently Asked Questions)

## 📋 General Questions

### What is TimeTracker?
TimeTracker is an enterprise-grade time tracking application designed for project and customer-based time management. It provides comprehensive features for tracking work hours, generating reports, and integrating with external systems like JIRA.

### Who uses TimeTracker?
- **Developers**: Track time on projects and tasks
- **Project Managers**: Monitor team productivity and project progress
- **Controllers**: Generate reports and analyze time allocation
- **Administrators**: Manage users, projects, and system configuration

### What are the main features?
- ⏱️ Time tracking with autocompletion
- 📊 Analytics and reporting dashboards
- 🔄 JIRA integration for worklog synchronization
- 👥 Team and project management
- 📈 Excel/CSV export capabilities
- 🔐 LDAP/Active Directory authentication
- 📱 Responsive web interface

## 🚀 Getting Started

### How do I access TimeTracker?
Access the application through your web browser at your organization's TimeTracker URL (typically `https://timetracker.yourcompany.com`). Use your LDAP credentials or local account to log in.

### What browsers are supported?
- Chrome 90+ (recommended)
- Firefox 88+
- Safari 14+
- Edge 90+

### Is there a mobile app?
Currently, TimeTracker is a responsive web application that works well on mobile browsers. Native mobile apps are not available yet.

## ⏰ Time Tracking

### How do I add a time entry?
1. Click **"Add Entry"** or press **'a'** keyboard shortcut
2. Select the date
3. Choose project and activity
4. Enter duration (e.g., "2h 30m" or "2.5")
5. Add a description
6. Click Save

### What time formats are supported?
```
1h 30m    → 1 hour 30 minutes
2.5       → 2.5 hours
90m       → 90 minutes
1w 2d 3h  → 1 week, 2 days, 3 hours
```

### Can I add entries for past dates?
Yes, you can add entries for any date within the allowed period (typically the current and previous month).

### How do I edit an existing entry?
Simply click on any field of the entry you want to edit. The field becomes editable immediately.

### How do I delete an entry?
- Right-click on the entry and select **"Delete"**
- Or select the entry and press **'d'** key

### What are bulk entries?
Bulk entries allow you to quickly add recurring entries like vacation or sick days for multiple days at once.

## 🔗 JIRA Integration

### How does JIRA integration work?
TimeTracker can automatically synchronize your time entries with JIRA worklogs. When you track time against a JIRA ticket, it creates or updates the corresponding worklog in JIRA.

### How do I connect my JIRA account?
1. Go to Settings → Integrations
2. Click "Connect JIRA Account"
3. Follow the OAuth authorization flow
4. Grant TimeTracker permission to access your JIRA account

### Why isn't my time showing in JIRA?
Common reasons:
- JIRA authentication expired (re-authenticate)
- Network connectivity issues
- JIRA ticket is closed or restricted
- Synchronization is still pending (check sync status)

### Can I disable JIRA sync for specific entries?
Yes, uncheck the "Sync to JIRA" option when creating or editing an entry.

## 👥 User Management

### What are the user roles?

| Role | Abbreviation | Permissions |
|------|--------------|-------------|
| Developer | DEV | Track time, view own reports |
| Controller | CTL | All DEV permissions + export data, view team reports |
| Project Leader | PL | All CTL permissions + manage projects, users, settings |

### How do I change my password?
If using LDAP authentication, change your password through your organization's standard process. For local accounts, go to Settings → Security → Change Password.

### Can I have multiple user accounts?
No, each person should have only one account. Contact an administrator if you need role changes.

## 📊 Reports and Analytics

### What reports are available?
- **Personal Dashboard**: Your time entries and statistics
- **Project Reports**: Time spent per project
- **Customer Reports**: Time allocation by customer
- **Team Reports**: Team productivity metrics (PL only)
- **Activity Reports**: Time distribution by activity type

### How do I export data?
1. Go to Controlling → Export
2. Select date range and filters
3. Choose format (Excel, CSV, JSON)
4. Click "Export"

### What is the "Interpretation" tab?
The Interpretation tab provides visual analytics with charts and graphs showing time distribution across projects, customers, and activities.

### Can I schedule automated reports?
Not directly in TimeTracker, but you can use the API to build automated reporting solutions.

## 🏢 Projects and Customers

### What's the difference between a project and a customer?
- **Customer**: The client organization (e.g., "Acme Corp")
- **Project**: Specific work for that customer (e.g., "Acme Website Redesign")

### How do I request a new project?
Contact your Project Leader or Administrator. They can create new projects through Administration → Projects.

### Can a project have multiple customers?
No, each project belongs to exactly one customer. However, a customer can have multiple projects.

### What are project presets?
Presets are templates for common activities that can be quickly applied when creating time entries.

## ⚙️ Settings and Configuration

### Where are my personal settings?
Click on your username in the top-right corner, then select "Settings" to configure:
- Time display format
- Default project/activity
- Notification preferences
- JIRA integration

### How do I set my default project?
Settings → Preferences → Default Project

### Can I change the interface language?
Currently, TimeTracker supports:
- English (default)
- German
- French
Change via Settings → Preferences → Language

## 🔒 Security and Privacy

### Is my data secure?
Yes, TimeTracker uses:
- Encrypted connections (HTTPS)
- Secure authentication (LDAP/OAuth)
- Role-based access control
- Regular security updates

### Who can see my time entries?
- You can always see your own entries
- Your Project Leader can see team entries
- Controllers can see entries for export/reporting
- Administrators have full access

### How long is data retained?
Time entries are retained according to your organization's data retention policy (typically 2-7 years).

### Can I delete my data?
You can delete your own recent entries. For older data or complete removal, contact an administrator.

## 🐛 Troubleshooting

### I can't log in
1. Check your username and password
2. Verify CAPS LOCK is off
3. Try resetting your password
4. Contact IT support if using LDAP

### The page is loading slowly
- Clear browser cache (Ctrl+Shift+R)
- Check your internet connection
- Try a different browser
- Report to IT if problem persists

### My entries aren't saving
- Check for validation errors (red fields)
- Ensure you have a stable internet connection
- Try refreshing the page
- Contact support if the issue continues

### I see an error message
Note the error code and message, then:
1. Try refreshing the page
2. Log out and back in
3. Contact support with the error details

## 📱 Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `a` | Add new entry |
| `d` | Delete selected entry |
| `e` | Edit selected entry |
| `↑/↓` | Navigate entries |
| `Ctrl+S` | Save current entry |
| `Esc` | Cancel editing |
| `/` | Focus search |
| `?` | Show help |

## 🔄 API and Integrations

### Is there an API?
Yes, TimeTracker provides a REST API for:
- Creating/reading time entries
- Managing projects and users
- Generating reports
- Webhook notifications

### How do I get API access?
1. Request API credentials from an administrator
2. Refer to the [API Documentation](./API_USAGE_GUIDE.md)
3. Use OAuth 2.0 for authentication

### What integrations are available?
- JIRA (built-in)
- LDAP/Active Directory (built-in)
- Slack (via webhooks)
- Custom integrations via API

### Can I build my own integration?
Yes, use the REST API to build custom integrations. See the [API Usage Guide](./API_USAGE_GUIDE.md) for details.

## 💡 Best Practices

### Daily time tracking tips
1. Track time as you work (not at end of day)
2. Use descriptive entry descriptions
3. Associate entries with specific tickets
4. Review entries before submitting

### Weekly workflow
1. Monday: Review previous week's entries
2. Daily: Track time as you work
3. Friday: Ensure week is complete
4. Submit for approval if required

### Project organization
- Use consistent activity types
- Keep project descriptions updated
- Archive completed projects
- Regular cleanup of old data

## 📞 Support

### How do I get help?
1. Check this FAQ first
2. Consult the [Troubleshooting Guide](./TROUBLESHOOTING.md)
3. Ask in #timetracker-support Slack channel
4. Email: timetracker-support@company.com
5. Create a support ticket

### How do I report a bug?
1. Document the issue with screenshots
2. Note steps to reproduce
3. Check if already reported
4. Submit via GitHub Issues or support ticket

### How do I request a feature?
1. Check if already requested
2. Describe the use case
3. Submit feature request via GitHub
4. Discuss in Slack channel

## 🔄 Updates and Maintenance

### How often is TimeTracker updated?
- **Minor updates**: Monthly
- **Security patches**: As needed
- **Major releases**: Quarterly

### Will I be notified of updates?
Yes, through:
- In-app notifications
- Email announcements
- Slack channel updates

### Is there scheduled maintenance?
Maintenance windows:
- Regular: Sunday 2-4 AM
- Emergency: As needed with notice

### What happens during maintenance?
The application may be unavailable briefly. Your data is safe and any unsaved work will be preserved.

---

*Can't find your answer? Contact support at timetracker-support@company.com*

*Last Updated: 2025-01-15 | Version: 1.0*