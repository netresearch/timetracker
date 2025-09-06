# TimeTracker Test Failure Fixes

## Fix Implementation Plan

### CRITICAL: Service Dependency Fixes (Estimated: 8-12 errors)

#### Problem: Deleted Validation Services
**Files affected**: Controllers referencing `ValidationService`, `CustomerValidator`, `UserValidator`, `ProjectValidator`

**Fix Actions**:

1. **Remove validation service constructor dependencies**:
```php
// OLD - Remove these from controller constructors:
private readonly ValidationService $validationService,
private readonly CustomerValidator $customerValidator,

// NEW - Use Symfony validator instead:
private readonly ValidatorInterface $validator,
```

2. **Replace validation service calls**:
```php
// OLD:
$errors = $this->validationService->validate($data);

// NEW:  
$errors = $this->validator->validate($dto);
```

3. **Update service container exclusions in services.yaml**:
```yaml
# Remove references to deleted validation services
# Already handled in current services.yaml
```

#### Specific Files to Fix:
- `src/Controller/Admin/Save*Action.php` - Remove validation service injections
- `src/Controller/Tracking/SaveEntryAction.php` - Use DTO validation instead
- Any controller with validation service dependencies

---

### CRITICAL: DTO Validation Test Fixes (Estimated: 6-8 failures)

#### Problem: New strict validation rules in EntrySaveDto

**Fix Actions**:

1. **Update EntrySaveDtoTest expectations**:
```php
// Fix testInvalidTicketFormat - update expected message
public function testInvalidTicketFormat(): void
{
    // OLD assertion:
    self::assertStringContainsString('Invalid ticket format', (string) $violations);
    
    // NEW - check actual constraint message:
    self::assertStringContainsString('Invalid ticket format', $violations[0]->getMessage());
}
```

2. **Fix validation count assertions**:
```php
// Check actual validation constraint count - may be different
self::assertCount(1, $violations); // Instead of assertGreaterThan
```

3. **Update time validation tests**:
```php  
// Update time format expectations to match new regex pattern
// Pattern: '/^\d{2}:\d{2}(:\d{2})?$/'
$dto = new EntrySaveDto(
    start: '09:00:00', // Ensure seconds included
    end: '17:00:00'    // Ensure seconds included  
);
```

#### Specific Files to Fix:
- `tests/Dto/EntrySaveDtoTest.php` - All test methods
- Controller tests posting form data - Update validation expectations

---

### IMPORTANT: Repository Method Fixes (Estimated: 4-6 failures)

#### Problem: DBAL method changes and new repository structure

**Fix Actions**:

1. **Update database query expectations in tests**:
```php
// Tests expecting different result formats from repository methods
// Check if tests use direct SQL queries that need updating

// OLD test pattern:
$result = $statement->execute()->fetch();

// NEW test pattern: 
$result = $statement->executeQuery()->fetchAssociative();
```

2. **Update EntryRepository test mocks**:
```php
// If tests mock repository methods, update method signatures
// Check for any hardcoded SQL result expectations
```

#### Specific Files to Fix:
- `tests/Repository/EntryRepositoryTest.php`
- `tests/Repository/EntryRepositoryIntegrationTest.php`
- Controller tests using direct database queries

---

### IMPORTANT: Service Container Configuration Fixes (Estimated: 3-5 errors)

#### Problem: Service exclusions and configuration changes

**Fix Actions**:

1. **Update test service configurations**:
```yaml
# In config/services_test.yaml or test environment
services:
    # Remove any references to excluded Jira services in tests
    # Add test doubles for excluded services if needed
```

2. **Fix circular reference issues**:
```php
// Check if any test setup creates circular service dependencies
// Update service injection order in test containers
```

#### Specific Files to Fix:
- `config/services_test.yaml` - Update test service overrides
- Test files bootstrapping services - Check container setup

---

### MODERATE: Deprecation Fixes (Estimated: 4 warnings)

#### Problem: Framework and PHP compatibility warnings

**Fix Actions**:

1. **Fix Symfony deprecations**:
```php
// Update any deprecated Symfony method calls
// Check for framework component usage warnings
```

2. **Fix PHP 8.4 compatibility**:
```php
// Address any null parameter passing deprecations
// Update method signatures for strict types
```

## Implementation Sequence

### Phase 1: Critical Service Fixes (Day 1)
1. Remove deleted validation service references
2. Update controller constructors  
3. Replace validation logic with Symfony validator

### Phase 2: DTO and Validation Tests (Day 1-2)
1. Fix EntrySaveDto test expectations
2. Update validation message assertions
3. Correct validation count expectations

### Phase 3: Repository and Database Tests (Day 2)
1. Update DBAL method expectations
2. Fix query result format assumptions
3. Update repository test mocks

### Phase 4: Configuration and Deprecations (Day 2-3)
1. Resolve service container conflicts
2. Update test service configurations  
3. Address framework deprecation warnings

## Expected Results After Fixes

- **Errors**: Reduced from 19 to 0
- **Failures**: Reduced from 8 to 0  
- **Deprecations**: Reduced from 4 to 0
- **Total test count**: All tests passing

## Validation Strategy

After implementing fixes:
1. Run full test suite: `composer test`
2. Run individual test categories: `composer test:unit`, `composer test:controller`
3. Check for any remaining service container issues
4. Verify all validation logic works as expected
5. Confirm no regression in core functionality