# Database Isolation Strategy - Phase 2+ Implementation Plan

## Problem Statement

Current test architecture suffers from systematic transaction rollback failures, requiring 21 manual `forceReset()` calls that prevent test isolation and parallel execution.

## Root Cause Analysis

- **Symptom**: All forceReset() calls have identical comment: "for some unknown reason the transaction is not rolled back"
- **Impact**: Tests are interdependent, slow, and cannot run in parallel
- **Risk**: Flaky test behavior and debugging complexity

## Recommended Solution Architecture

### Option 1: dama/doctrine-test-bundle (Recommended)
**Benefits:**
- Automatic transaction wrapping per test
- Complete elimination of forceReset() calls
- Enables parallel test execution
- Industry standard approach

**Implementation Plan:**
1. Install `dama/doctrine-test-bundle` package
2. Configure in test environment only
3. Remove all forceReset() method calls
4. Validate existing test behavior
5. Enable parallel execution

**Risk Assessment: HIGH**
- Complex integration with existing Docker test setup
- Potential conflicts with current data loading approach
- Requires extensive regression testing
- Should be implemented in dedicated sprint

### Option 2: Test Data Builder Pattern (Incremental)
**Benefits:**
- Reduces raw SQL dependency
- Improves test readability
- Can be implemented incrementally

**Implementation:**
```php
class EntryTestDataBuilder {
    public static function create(): Entry {
        return (new Entry())
            ->setUser(UserTestDataBuilder::create())
            ->setProject(ProjectTestDataBuilder::create())
            ->setDuration(480)
            ->setDate(new DateTime());
    }
}
```

## Current Status
- **Phase 1 Completed**: Removed 66 misleading patterns and legacy assertions
- **Phase 2 Scope**: This database architecture change requires strategic planning
- **Recommendation**: Include in next sprint planning as dedicated epic

## Implementation Prerequisites
1. Comprehensive test backup and rollback strategy
2. Development environment validation
3. Gradual rollout with feature flags
4. Performance benchmarking before/after

## Success Metrics
- Zero forceReset() calls remaining
- Tests pass with parallel execution enabled
- Test execution time reduced by 40%+
- No test interdependency failures