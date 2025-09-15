# TimeTracker API Usage Guide

**Comprehensive guide to using the TimeTracker REST API with practical examples**

---

## Table of Contents

1. [Authentication](#authentication)
2. [Time Entry Management](#time-entry-management)
3. [Project & Customer APIs](#project--customer-apis)
4. [Reporting & Analytics](#reporting--analytics)
5. [Bulk Operations](#bulk-operations)
6. [Error Handling](#error-handling)
7. [Rate Limiting](#rate-limiting)
8. [Webhooks](#webhooks)
9. [SDK Examples](#sdk-examples)

---

## Authentication

### Login & Token Acquisition

The TimeTracker API uses JWT tokens for authentication with configurable expiration.

#### Login with LDAP Credentials

```bash
# Login request
curl -X POST http://localhost:8765/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "john.doe",
    "password": "your_password"
  }'

# Response
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def502008f7c...",
  "expires_in": 3600,
  "user": {
    "id": 123,
    "username": "john.doe",
    "email": "john.doe@company.com",
    "roles": ["ROLE_DEV"],
    "teams": [1, 3]
  }
}
```

#### Token Refresh

```bash
# Refresh expired token
curl -X POST http://localhost:8765/api/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{
    "refresh_token": "def502008f7c..."
  }'
```

#### Using Tokens in Requests

```bash
# Set token as environment variable
export JWT_TOKEN="eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9..."

# Use in subsequent requests
curl -H "Authorization: Bearer $JWT_TOKEN" \
  http://localhost:8765/api/entries
```

### Service User Authentication

For automated integrations, use service users:

```bash
# Service user with user delegation
curl -X POST http://localhost:8765/api/entries \
  -H "Authorization: Bearer $SERVICE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user": 456,
    "start": "09:00",
    "end": "17:00",
    "description": "Automated data sync",
    "project": 1
  }'
```

---

## Time Entry Management

### Create Time Entry

```bash
# Basic time entry
curl -X POST http://localhost:8765/api/entries \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "day": "2024-01-15",
    "start": "09:00",
    "end": "17:00",
    "description": "Feature development",
    "project": 1,
    "activity": 2,
    "ticket": "PROJ-123"
  }'

# Response
{
  "id": 789,
  "day": "2024-01-15",
  "start": "09:00",
  "end": "17:00",
  "duration": 480,
  "description": "Feature development",
  "project": {
    "id": 1,
    "name": "Web Application"
  },
  "ticket": "PROJ-123",
  "created_at": "2024-01-15T09:00:00Z",
  "updated_at": "2024-01-15T09:00:00Z"
}
```

### Entry with Duration (instead of start/end)

```bash
# Duration-based entry
curl -X POST http://localhost:8765/api/entries \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "day": "2024-01-15",
    "duration": 240,
    "description": "Morning meeting",
    "project": 1
  }'
```

### Retrieve Entries

```bash
# Get entries for specific date
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/entries?date=2024-01-15"

# Get entries for date range
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/entries?start=2024-01-01&end=2024-01-31"

# Get entries with filters
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/entries?project=1&ticket=PROJ-123&limit=50"

# Response
{
  "entries": [
    {
      "id": 789,
      "day": "2024-01-15",
      "start": "09:00",
      "end": "17:00",
      "duration": 480,
      "description": "Feature development",
      "project": {
        "id": 1,
        "name": "Web Application",
        "customer": {
          "id": 1,
          "name": "Acme Corp"
        }
      }
    }
  ],
  "total": 1,
  "page": 1,
  "pages": 1
}
```

### Update Entry

```bash
# Update specific entry
curl -X PUT http://localhost:8765/api/entries/789 \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Feature development (updated)",
    "end": "18:00",
    "ticket": "PROJ-124"
  }'
```

### Delete Entry

```bash
# Delete entry
curl -X DELETE http://localhost:8765/api/entries/789 \
  -H "Authorization: Bearer $JWT_TOKEN"

# Response
{
  "message": "Entry deleted successfully",
  "deleted_id": 789
}
```

### Time Entry Validation

The API automatically validates entries for:
- **Overlapping entries**: Prevents time conflicts
- **Maximum daily hours**: Configurable limits
- **Required fields**: Based on project settings
- **Future dates**: Restrictions on future time logging

```bash
# Validation error response
{
  "error": "validation_failed",
  "message": "Time entry validation failed",
  "violations": [
    {
      "field": "end",
      "message": "End time cannot be before start time"
    },
    {
      "field": "day",
      "message": "Cannot log time more than 30 days in the future"
    }
  ]
}
```

---

## Project & Customer APIs

### List Projects

```bash
# Get all active projects for current user
curl -H "Authorization: Bearer $JWT_TOKEN" \
  http://localhost:8765/api/projects

# Get projects with customer information
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/projects?include=customer,activities"

# Response
{
  "projects": [
    {
      "id": 1,
      "name": "Web Application",
      "active": true,
      "customer": {
        "id": 1,
        "name": "Acme Corp",
        "active": true
      },
      "activities": [
        {
          "id": 1,
          "name": "Development"
        },
        {
          "id": 2,
          "name": "Testing"
        }
      ],
      "ticket_system": {
        "id": 1,
        "name": "JIRA",
        "ticket_url": "https://company.atlassian.net/browse/%s"
      }
    }
  ]
}
```

### Project Details

```bash
# Get detailed project information
curl -H "Authorization: Bearer $JWT_TOKEN" \
  http://localhost:8765/api/projects/1

# Response includes team assignments, settings, integrations
{
  "id": 1,
  "name": "Web Application",
  "description": "Main web application development",
  "customer": { ... },
  "teams": [1, 3],
  "settings": {
    "require_ticket": true,
    "allow_future_entries": false,
    "max_daily_hours": 12
  },
  "integrations": {
    "jira": {
      "enabled": true,
      "project_key": "WEB"
    }
  }
}
```

### Customer Management

```bash
# List customers (requires PL role)
curl -H "Authorization: Bearer $JWT_TOKEN" \
  http://localhost:8765/api/customers

# Create new customer
curl -X POST http://localhost:8765/api/customers \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New Client Corp",
    "active": true
  }'
```

---

## Reporting & Analytics

### Time Summary Reports

```bash
# Daily summary for user
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/reports/summary?date=2024-01-15"

# Weekly summary
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/reports/summary?week=2024-W03"

# Monthly summary by project
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/reports/summary?month=2024-01&group=project"

# Response
{
  "summary": {
    "period": "2024-01-15",
    "total_hours": 8.0,
    "total_entries": 3,
    "breakdown": {
      "by_project": {
        "Web Application": 6.5,
        "Internal Tasks": 1.5
      },
      "by_activity": {
        "Development": 5.0,
        "Meetings": 2.0,
        "Documentation": 1.0
      }
    }
  }
}
```

### Team Analytics

```bash
# Team performance (requires CTL role)
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/reports/team?team=1&month=2024-01"

# Project progress tracking
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/reports/project/1?start=2024-01-01&end=2024-01-31"
```

### Export Reports

```bash
# Export to Excel
curl -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" \
  "http://localhost:8765/api/reports/export?format=xlsx&month=2024-01" \
  --output timetracker-2024-01.xlsx

# Export to CSV
curl -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Accept: text/csv" \
  "http://localhost:8765/api/reports/export?format=csv&project=1&month=2024-01" \
  --output project-1-2024-01.csv
```

---

## Bulk Operations

### Bulk Entry Creation

Perfect for vacation, sick leave, or recurring tasks:

```bash
# Vacation entries for multiple days
curl -X POST http://localhost:8765/api/entries/bulk \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "entries": [
      {
        "day": "2024-02-01",
        "preset": "vacation",
        "duration": 480,
        "description": "Annual leave"
      },
      {
        "day": "2024-02-02", 
        "preset": "vacation",
        "duration": 480,
        "description": "Annual leave"
      }
    ]
  }'

# Recurring meetings
curl -X POST http://localhost:8765/api/entries/bulk \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "template": {
      "start": "09:00",
      "end": "10:00",
      "description": "Daily standup",
      "project": 1,
      "activity": 3
    },
    "dates": ["2024-02-01", "2024-02-02", "2024-02-05", "2024-02-06"]
  }'
```

### Bulk Updates

```bash
# Update multiple entries (change project assignment)
curl -X PATCH http://localhost:8765/api/entries/bulk \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "filter": {
      "project": 1,
      "date_range": {
        "start": "2024-01-01",
        "end": "2024-01-31"
      }
    },
    "updates": {
      "project": 2,
      "description": "Moved to new project"
    }
  }'

# Response
{
  "updated_count": 23,
  "entries": [
    {"id": 789, "project": 2},
    {"id": 790, "project": 2}
  ]
}
```

### Bulk Delete

```bash
# Delete entries matching criteria
curl -X DELETE http://localhost:8765/api/entries/bulk \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "filter": {
      "ticket": "CANCELLED-123",
      "user": 456
    }
  }'
```

---

## Error Handling

### HTTP Status Codes

| Code | Meaning | Usage |
|------|---------|--------|
| `200` | Success | Successful GET, PUT, PATCH |
| `201` | Created | Successful POST |
| `204` | No Content | Successful DELETE |
| `400` | Bad Request | Invalid input data |
| `401` | Unauthorized | Missing or invalid token |
| `403` | Forbidden | Insufficient permissions |
| `404` | Not Found | Resource doesn't exist |
| `409` | Conflict | Validation rules violated |
| `422` | Unprocessable Entity | Business logic violation |
| `429` | Too Many Requests | Rate limit exceeded |
| `500` | Internal Server Error | Server-side error |

### Error Response Format

```json
{
  "error": "validation_failed",
  "message": "The request data failed validation",
  "details": {
    "field": "start_time",
    "constraint": "overlapping_entry",
    "conflicting_entry": 123
  },
  "timestamp": "2024-01-15T14:30:00Z",
  "request_id": "req_abc123"
}
```

### Common Error Scenarios

#### 1. Overlapping Time Entries

```bash
# Request that creates overlap
curl -X POST http://localhost:8765/api/entries \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "day": "2024-01-15",
    "start": "14:00",
    "end": "18:00",
    "description": "New task",
    "project": 1
  }'

# Error response
{
  "error": "overlapping_entry",
  "message": "Time entry overlaps with existing entry",
  "details": {
    "conflicting_entry": {
      "id": 456,
      "start": "13:00",
      "end": "16:00",
      "description": "Existing task"
    },
    "overlap_duration": 120
  }
}
```

#### 2. Permission Denied

```bash
# Attempting to access other user's data
curl -H "Authorization: Bearer $JWT_TOKEN" \
  http://localhost:8765/api/entries?user=999

# Error response
{
  "error": "access_denied",
  "message": "Insufficient permissions to access user data",
  "required_role": "ROLE_CTL",
  "current_roles": ["ROLE_DEV"]
}
```

#### 3. Rate Limiting

```json
{
  "error": "rate_limit_exceeded",
  "message": "Too many requests",
  "limit": 100,
  "remaining": 0,
  "reset_at": "2024-01-15T15:00:00Z"
}
```

---

## Rate Limiting

The API implements rate limiting to ensure fair usage:

### Rate Limits by User Role

| Role | Requests/Hour | Burst Limit |
|------|---------------|-------------|
| DEV | 1000 | 50 |
| CTL | 2000 | 100 |
| PL | 5000 | 200 |
| Service User | 10000 | 500 |

### Rate Limit Headers

```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987
X-RateLimit-Reset: 1642248000
```

### Handling Rate Limits

```bash
# Check rate limit status
curl -I -H "Authorization: Bearer $JWT_TOKEN" \
  http://localhost:8765/api/entries

# Implement exponential backoff
for i in {1..5}; do
  response=$(curl -w "%{http_code}" -s -H "Authorization: Bearer $JWT_TOKEN" \
    http://localhost:8765/api/entries)
  
  if [ "$response" != "429" ]; then
    break
  fi
  
  sleep $((2**i))
done
```

---

## Webhooks

Configure webhooks to receive real-time notifications about time entry changes.

### Webhook Configuration

```bash
# Register webhook (requires PL role)
curl -X POST http://localhost:8765/api/webhooks \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://your-app.com/webhook/timetracker",
    "events": ["entry.created", "entry.updated", "entry.deleted"],
    "secret": "your-webhook-secret",
    "active": true
  }'
```

### Webhook Events

| Event | Description | Payload |
|-------|-------------|---------|
| `entry.created` | New time entry | Entry object |
| `entry.updated` | Entry modified | Entry object + changes |
| `entry.deleted` | Entry removed | Entry ID + user |
| `project.created` | New project | Project object |
| `user.login` | User authenticated | User object |

### Webhook Payload Example

```json
{
  "event": "entry.created",
  "timestamp": "2024-01-15T14:30:00Z",
  "data": {
    "id": 789,
    "user": {
      "id": 123,
      "username": "john.doe"
    },
    "day": "2024-01-15",
    "duration": 480,
    "project": {
      "id": 1,
      "name": "Web Application"
    }
  },
  "signature": "sha256=abc123..."
}
```

### Webhook Security

Verify webhook signatures to ensure authenticity:

```python
# Python example
import hmac
import hashlib

def verify_webhook(payload, signature, secret):
    expected = hmac.new(
        secret.encode(),
        payload.encode(),
        hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(f"sha256={expected}", signature)
```

---

## SDK Examples

### JavaScript/Node.js

```javascript
// TimeTracker API Client
class TimeTrackerAPI {
  constructor(baseUrl, token) {
    this.baseUrl = baseUrl;
    this.token = token;
  }

  async createEntry(entryData) {
    const response = await fetch(`${this.baseUrl}/api/entries`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(entryData)
    });
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${await response.text()}`);
    }
    
    return response.json();
  }

  async getEntries(filters = {}) {
    const params = new URLSearchParams(filters);
    const response = await fetch(`${this.baseUrl}/api/entries?${params}`, {
      headers: {
        'Authorization': `Bearer ${this.token}`
      }
    });
    
    return response.json();
  }
}

// Usage
const api = new TimeTrackerAPI('http://localhost:8765', 'your-jwt-token');

await api.createEntry({
  day: '2024-01-15',
  start: '09:00',
  end: '17:00',
  description: 'Feature development',
  project: 1
});
```

### Python

```python
import requests
from datetime import datetime

class TimeTrackerClient:
    def __init__(self, base_url, token):
        self.base_url = base_url.rstrip('/')
        self.session = requests.Session()
        self.session.headers.update({
            'Authorization': f'Bearer {token}',
            'Content-Type': 'application/json'
        })
    
    def create_entry(self, **kwargs):
        """Create a new time entry"""
        response = self.session.post(f'{self.base_url}/api/entries', json=kwargs)
        response.raise_for_status()
        return response.json()
    
    def get_entries(self, **filters):
        """Get time entries with optional filters"""
        response = self.session.get(f'{self.base_url}/api/entries', params=filters)
        response.raise_for_status()
        return response.json()
    
    def bulk_create_vacation(self, start_date, end_date, duration=480):
        """Create vacation entries for a date range"""
        entries = []
        current = datetime.strptime(start_date, '%Y-%m-%d')
        end = datetime.strptime(end_date, '%Y-%m-%d')
        
        while current <= end:
            # Skip weekends
            if current.weekday() < 5:
                entries.append({
                    'day': current.strftime('%Y-%m-%d'),
                    'preset': 'vacation',
                    'duration': duration,
                    'description': 'Annual leave'
                })
            current += timedelta(days=1)
        
        return self.session.post(f'{self.base_url}/api/entries/bulk', 
                               json={'entries': entries}).json()

# Usage
client = TimeTrackerClient('http://localhost:8765', 'your-jwt-token')

# Create single entry
entry = client.create_entry(
    day='2024-01-15',
    start='09:00',
    end='17:00',
    description='API integration work',
    project=1
)

# Bulk vacation creation
vacation_result = client.bulk_create_vacation('2024-02-01', '2024-02-05')
```

### PHP

```php
<?php

class TimeTrackerAPI 
{
    private string $baseUrl;
    private string $token;
    
    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }
    
    public function createEntry(array $data): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/api/entries',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 201) {
            throw new Exception("HTTP $httpCode: $response");
        }
        
        return json_decode($response, true);
    }
    
    public function getMonthlyReport(int $year, int $month): array
    {
        $url = sprintf('%s/api/reports/summary?month=%d-%02d', 
                      $this->baseUrl, $year, $month);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token
            ],
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}

// Usage
$api = new TimeTrackerAPI('http://localhost:8765', 'your-jwt-token');

$entry = $api->createEntry([
    'day' => '2024-01-15',
    'start' => '09:00',
    'end' => '17:00',
    'description' => 'PHP integration work',
    'project' => 1
]);

$report = $api->getMonthlyReport(2024, 1);
```

---

## Interactive API Testing

### Swagger/OpenAPI Documentation

Access the interactive API documentation at:
```
http://localhost:8765/docs/swagger/index.html
```

Features:
- **Interactive testing** directly in the browser
- **Authentication** with JWT tokens
- **Request/response examples** for all endpoints
- **Schema validation** and field descriptions

### Postman Collection

Import the TimeTracker API collection:

```bash
# Download collection
curl -o timetracker-api.postman_collection.json \
  http://localhost:8765/api/postman/collection

# Import into Postman and set environment variables:
# - base_url: http://localhost:8765
# - jwt_token: your-authentication-token
```

### HTTPie Examples

```bash
# Install HTTPie
pip install httpie

# Login and save token
http POST localhost:8765/api/auth/login username=john.doe password=secret

# Create entry with HTTPie
http POST localhost:8765/api/entries \
  Authorization:"Bearer $JWT_TOKEN" \
  day=2024-01-15 \
  start=09:00 \
  end=17:00 \
  description="HTTPie test" \
  project:=1

# Get entries with filters  
http localhost:8765/api/entries \
  Authorization:"Bearer $JWT_TOKEN" \
  date==2024-01-15 \
  project==1
```

---

## Performance Considerations

### Pagination

Large result sets are automatically paginated:

```bash
# Use pagination parameters
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/entries?page=1&limit=100"

# Response includes pagination metadata
{
  "entries": [...],
  "pagination": {
    "page": 1,
    "limit": 100,
    "total": 1250,
    "pages": 13,
    "has_next": true,
    "has_prev": false
  }
}
```

### Caching

API responses include cache headers:

```http
Cache-Control: public, max-age=300
ETag: "abc123def456"
Last-Modified: Mon, 15 Jan 2024 14:30:00 GMT
```

Use conditional requests for better performance:

```bash
# Use ETags for conditional requests
curl -H "Authorization: Bearer $JWT_TOKEN" \
  -H "If-None-Match: abc123def456" \
  http://localhost:8765/api/projects
```

### Field Selection

Reduce payload size with field selection:

```bash
# Only get specific fields
curl -H "Authorization: Bearer $JWT_TOKEN" \
  "http://localhost:8765/api/entries?fields=id,day,duration,description"
```

---

**🎉 You're now ready to integrate with the TimeTracker API!** 

For additional help:
- 📚 [Complete API Reference](API_DOCUMENTATION.md)
- 🔒 [Security Best Practices](SECURITY_IMPLEMENTATION_GUIDE.md)  
- 🐛 [Troubleshooting Guide](DEVELOPER_SETUP.md#troubleshooting)

---

**Last Updated**: 2025-01-20  
**API Version**: v0.1.9  
**Questions**: Create a GitHub issue or contact the development team