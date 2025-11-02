# PHPStan Level 10 Compliance Fixes

## Current Status: 151 errors to fix

### Error Categories and Fix Strategy:

1. **argument.type (23 errors)** - Fix type mismatches in method arguments
2. **offsetAccess.nonOffsetAccessible (11 errors)** - Add type guards for array access
3. **staticMethod.alreadyNarrowedType (7 errors)** - Improve PHPUnit assertions
4. **missingType.parameter (7 errors)** - Add parameter type hints
5. **missingType.return (4 errors)** - Add return type hints
6. **missingType.property (4 errors)** - Add property type hints
7. **cast.string (2 errors)** - Fix string casting issues
8. **foreach.nonIterable (2 errors)** - Add iteration guards
9. **deadCode.unreachable (2 errors)** - Remove unreachable code
10. **nullCoalesce.variable (2 errors)** - Fix null coalescing patterns

### Fix Priority:
1. Core service files (critical for functionality)
2. Test infrastructure (important for quality)
3. Performance/tooling files (lower priority)

### Files to Process:
- Core services: JiraOAuthApiService, TokenEncryptionService, etc.
- Test fixtures and stubs
- Performance testing utilities
- Coverage analysis tools