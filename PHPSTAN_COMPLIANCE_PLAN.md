# PHPStan Level 10 Compliance Plan

## Current Status
- **Total Errors**: 151
- **Most Common Issues**:
  1. `argument.type` (23 errors) - Type mismatches in function/method arguments
  2. `offsetAccess.nonOffsetAccessible` (11 errors) - Array access on non-array types
  3. `staticMethod.alreadyNarrowedType` (7 errors) - Redundant PHPUnit assertions
  4. `missingType.parameter` (7 errors) - Missing parameter type hints
  5. `missingType.return` (4 errors) - Missing return type hints
  6. `missingType.property` (4 errors) - Missing property type hints

## Fixing Strategy by Priority

### High Priority (Type Safety Critical)
1. **Missing Type Annotations** - Add proper type hints
2. **Type Mismatches** - Fix argument type issues
3. **Array Access Issues** - Add proper type guards

### Medium Priority (Code Quality)
1. **Redundant Assertions** - Improve test code quality
2. **Dead Code** - Remove unreachable statements

### Low Priority (Cleanup)
1. **Unused Code** - Remove unused traits/variables
2. **Deprecated Patterns** - Update to modern patterns

## Files to Fix (in order)
1. Test infrastructure files
2. Core service files
3. Controller files
4. Entity/Model files
5. Performance/tool files