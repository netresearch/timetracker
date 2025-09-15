# ADR-003: JIRA Integration Architecture

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, Integration Team  

## Context

The TimeTracker application requires robust JIRA integration for bidirectional worklog synchronization, supporting multiple JIRA instances across enterprise environments. The integration must handle OAuth authentication, real-time sync, error recovery, and maintain data consistency while providing excellent user experience.

### Requirements
- **Multi-Instance Support**: Connect to multiple JIRA instances per tenant
- **Bidirectional Sync**: TimeTracker ↔ JIRA worklog synchronization
- **Authentication**: Secure OAuth-based authentication with token management
- **Error Resilience**: Handle network failures, authentication expiration, API rate limits
- **Performance**: Process 1000+ worklog entries without blocking UI
- **Security**: Encrypted token storage, audit logging, secure API communication

### Current Integration Challenges
- OAuth 1.0a complexity with RSA-SHA1 signatures
- Token expiration handling and automatic refresh
- JIRA API rate limiting (10 requests/second default)
- Network timeout and retry strategies
- Large-scale worklog synchronization performance

## Decision

We will implement **OAuth 1.0a** with **asynchronous push-based synchronization** using a resilient architecture pattern.

### Architecture Components

**1. OAuth 1.0a Authentication Strategy**
- RSA-SHA1 signature method for enhanced security
- Encrypted token storage using AES-256
- Automatic token refresh with fallback mechanisms
- Per-tenant certificate management

**2. Synchronization Strategy: Push over Pull**
```php
// Push-based synchronization (chosen approach)
class JiraWorkLogService 
{
    public function syncTimeEntry(Entry $entry): void 
    {
        // Immediate push to JIRA when entry is saved/updated
        $this->messageQueue->dispatch(new SyncWorklogMessage($entry->getId()));
    }
}

// Alternative: Pull-based (rejected)
// - Higher latency, complex conflict resolution
// - Requires polling, increased API usage
```

**3. Resilience Pattern Implementation**
```php
class JiraIntegrationService
{
    public function syncWithRetry(Entry $entry, int $maxAttempts = 3): void
    {
        $attempt = 0;
        $backoffMs = 1000; // Start with 1s backoff
        
        do {
            try {
                $this->jiraApi->updateEntryJiraWorkLog($entry);
                return; // Success, exit retry loop
                
            } catch (JiraApiException $e) {
                if ($e instanceof JiraApiUnauthorizedException) {
                    $this->handleTokenExpiration($entry->getUser());
                } elseif ($e->getCode() === 429) { // Rate limit
                    sleep($backoffMs / 1000);
                    $backoffMs *= 2; // Exponential backoff
                } else {
                    $this->logger->error('JIRA sync failed', [
                        'entry_id' => $entry->getId(),
                        'error' => $e->getMessage(),
                        'attempt' => $attempt + 1
                    ]);
                }
                
                $attempt++;
            }
        } while ($attempt < $maxAttempts);
        
        // Mark entry for manual review after max attempts
        $entry->setSyncStatus(SyncStatus::FAILED);
    }
}
```

## Implementation Details

### OAuth 1.0a vs OAuth 2.0 Decision

**Why OAuth 1.0a:**
- **JIRA Compatibility**: Many enterprise JIRA instances still use OAuth 1.0a
- **Security**: RSA signatures provide non-repudiation, better for audit trails
- **Token Longevity**: Tokens don't expire automatically, reducing auth interruptions
- **Enterprise Requirements**: Compliance with existing enterprise security policies

**OAuth 2.0 Considerations:**
- Simpler implementation but requires HTTPS everywhere
- Shorter token lifetimes increase authentication friction
- Limited support in older JIRA Server instances (pre-8.0)

### Service Architecture
```php
interface JiraIntegrationInterface 
{
    public function authenticateUser(User $user, TicketSystem $jira): string;
    public function syncWorklog(Entry $entry): SyncResult;
    public function validateTicket(string $ticketKey): bool;
    public function getSubtickets(string $parentTicket): array;
}

class JiraOAuthApiService implements JiraIntegrationInterface
{
    private Client $httpClient;
    private TokenEncryptionService $tokenService;
    private CacheInterface $cache;
    
    // OAuth handshake implementation
    // Worklog CRUD operations  
    // Error handling and retry logic
}
```

### Error Handling Strategy

**Network Failures:**
- Exponential backoff: 1s → 2s → 4s → 8s
- Circuit breaker pattern for repeated failures
- Fallback to offline mode with sync queue

**Authentication Issues:**
- Automatic token refresh attempt
- Graceful degradation to read-only mode
- User notification for re-authentication required

**API Rate Limiting:**
- Respect JIRA rate limits (10 req/sec default)
- Request queuing with priority (user actions > batch sync)
- Adaptive rate limiting based on response headers

**Data Conflicts:**
- Timestamp-based conflict resolution
- User notification for manual resolution
- Audit trail for all conflict resolutions

### Synchronization Patterns

**1. Real-time Sync (User Actions):**
```php
public function onEntryUpdated(EntryUpdatedEvent $event): void
{
    $entry = $event->getEntry();
    
    if ($entry->shouldSyncToJira()) {
        // High priority - immediate sync
        $this->syncService->syncImmediate($entry);
    }
}
```

**2. Batch Sync (Background):**
```php
#[AsCommand(name: 'jira:sync-pending')]
class JiraSyncCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->entryRepository->findPendingJiraSync(100);
        
        foreach ($entries as $entry) {
            $this->syncService->syncWithRetry($entry);
        }
        
        return Command::SUCCESS;
    }
}
```

**3. Conflict Resolution:**
```php
class ConflictResolutionService
{
    public function resolveWorklogConflict(Entry $entry, object $jiraWorklog): void
    {
        // TimeTracker wins for recent changes (< 5 minutes)
        if ($entry->getUpdatedAt() > (new \DateTime())->modify('-5 minutes')) {
            $this->pushToJira($entry);
            return;
        }
        
        // JIRA wins for older changes, notify user
        $this->notifyConflict($entry, $jiraWorklog);
        $entry->setSyncStatus(SyncStatus::CONFLICT_PENDING);
    }
}
```

## Consequences

### Positive
- **Robust Authentication**: OAuth 1.0a with RSA signatures provides enterprise-grade security
- **Real-time Updates**: Push-based sync provides immediate feedback to users
- **Error Resilience**: Comprehensive error handling ensures data integrity
- **Performance**: Asynchronous processing doesn't block UI operations
- **Audit Trail**: Complete synchronization history for compliance
- **Multi-tenant**: Supports multiple JIRA instances with isolated configurations

### Negative
- **Complexity**: OAuth 1.0a implementation is more complex than OAuth 2.0
- **Certificate Management**: RSA key pairs require secure generation and storage
- **Dependencies**: Additional message queue infrastructure for async processing
- **Debugging**: Distributed sync process can be challenging to troubleshoot

### Security Implementation
```php
class TokenEncryptionService
{
    public function encryptToken(string $token): string
    {
        $key = $this->getEncryptionKey();
        $iv = random_bytes(16);
        
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public function decryptToken(string $encryptedToken): string
    {
        $data = base64_decode($encryptedToken);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->getEncryptionKey(), 0, $iv);
    }
}
```

### Performance Monitoring
- Track sync success/failure rates by JIRA instance
- Monitor API response times and rate limit usage  
- Alert on authentication failures requiring user intervention
- Dashboard showing sync queue length and processing times

### Migration Path
1. **Phase 1**: Implement OAuth 1.0a authentication flow
2. **Phase 2**: Add worklog CRUD operations with error handling  
3. **Phase 3**: Implement async sync with message queue
4. **Phase 4**: Add conflict resolution and user notifications
5. **Phase 5**: Performance optimization and monitoring

This architecture ensures reliable, secure, and performant JIRA integration while maintaining excellent user experience and enterprise compliance requirements.