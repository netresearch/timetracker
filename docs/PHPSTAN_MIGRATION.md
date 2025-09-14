# PHPStan Level 10 Migration Guide

## Overview

PHPStan has been upgraded to the maximum strictness level (level 10) with bleeding edge configuration and all strict rules enabled. A baseline has been created to allow gradual migration of the existing codebase.

## Current Configuration

- **Level**: 10 (maximum)
- **Bleeding Edge**: Enabled
- **Strict Rules**: All enabled via phpstan-strict-rules extension
- **Extensions**:
  - phpstan-strict-rules
  - phpstan-deprecation-rules
  - phpstan-doctrine
  - phpstan-symfony
  - phpstan-phpunit
  - sfp-phpstan-psr-log (PSR-3 logger validation)

## Baseline Statistics

Total errors baselined: **1228**

### Error Categories

| Error Type | Count | Description |
|------------|-------|-------------|
| argument.type | 283 | Type mismatches in function/method arguments |
| method.nonObject | 177 | Calling methods on potentially null values |
| staticMethod.dynamicCall | 100 | Dynamic static method calls |
| offsetAccess.nonOffsetAccessible | 99 | Array access on non-array types |
| missingType.iterableValue | 70 | Missing generic types for iterables |
| varTag.type | 53 | Repository generic type annotations |
| booleanNot.exprNotBoolean | 46 | Non-boolean values in negation |
| ternary.shortNotAllowed | 35 | Short ternary operator usage |
| return.type | 26 | Return type mismatches |
| sfpPsrLog.messageNotStaticString | 18 | Non-static PSR-3 log messages |
| Others | 321 | Various other strict checks |

## Migration Strategy

### Phase 1: Critical Type Safety (Priority: High)
- Fix repository generic type annotations (53 errors)
- Address null safety issues (method.nonObject, booleanNot)
- Fix return type mismatches

### Phase 2: Strict Boolean Checks (Priority: Medium)
- Replace short ternary operators with null coalesce or long ternary
- Fix boolean expressions in conditions
- Ensure proper boolean types in logical operators

### Phase 3: Type Completeness (Priority: Low)
- Add missing generic types for arrays and iterables
- Fix argument type mismatches
- Address dynamic method calls

## How to Work with the Baseline

### Running PHPStan
```bash
# Run analysis (uses baseline automatically)
composer analyze

# Regenerate baseline after fixing errors
php bin/phpstan analyze --generate-baseline
```

### Fixing Baselined Errors
1. Choose an error category from the baseline
2. Fix all instances of that error type
3. Run PHPStan to verify fixes
4. Regenerate baseline to remove fixed errors

### Best Practices
- Fix entire categories at once for consistency
- Start with high-impact, easy-to-fix issues
- Use PHPStan's automatic fixes where available
- Add proper type declarations rather than suppressing errors

## Common Fixes

### Repository Generic Types
```php
// Before
/** @var UserRepository */
private UserRepository $userRepository;

// After
/** @var UserRepository<User> */
private UserRepository $userRepository;
```

### Boolean Expressions
```php
// Before
if (!$user) { }

// After
if ($user === null) { }
```

### Short Ternary
```php
// Before
$value = $input ?: 'default';

// After
$value = $input ?? 'default';  // for null checks
$value = $input !== null ? $input : 'default';  // for truthy checks
```

### PSR-3 Logger Usage
```php
// Before - dynamic message
$logger->error($exception->getMessage(), ['exception' => get_class($exception)]);

// After - static message with placeholder and proper exception
$logger->error('Exception occurred: {message}', [
    'message' => $exception->getMessage(),
    'exception' => $exception  // Must be Throwable object, not string
]);
```

## Benefits of Level 10

- **Type Safety**: Catches potential null pointer exceptions
- **Code Quality**: Enforces consistent coding patterns
- **Documentation**: Better IDE support through proper types
- **Refactoring Safety**: Changes are validated at static analysis time
- **Performance**: Some checks can identify performance issues

## Gradual Migration Timeline

1. **Immediate**: Use baseline to allow development to continue
2. **Sprint 1-2**: Fix critical type safety issues
3. **Sprint 3-4**: Address strict boolean checks
4. **Sprint 5-6**: Complete type annotations
5. **Ongoing**: Fix errors as files are modified

## Resources

- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)
- [Strict Rules](https://github.com/phpstan/phpstan-strict-rules)
- [Bleeding Edge](https://phpstan.org/blog/what-is-bleeding-edge)
- [Baseline Feature](https://phpstan.org/user-guide/baseline)