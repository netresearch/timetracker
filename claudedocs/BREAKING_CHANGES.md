# Breaking Changes Documentation

## Overview

This document outlines all breaking changes introduced during the PHPStan Level 9 compliance and type safety improvements. These changes improve code quality and type safety but may require updates to dependent code.

## 1. Type Safety Helper Introduction

### New Class: `App\Service\TypeSafety\ArrayTypeHelper`

**Location**: `src/Service/TypeSafety/ArrayTypeHelper.php`

**Purpose**: Provides type-safe methods for extracting values from mixed arrays, eliminating unsafe type casting.

**Usage**:
```php
// Before (unsafe)
$value = (int) $array['key'];

// After (type-safe)
$value = ArrayTypeHelper::getInt($array, 'key', 0);
```

**Available Methods**:
- `getInt(array $array, string $key, ?int $default = null): ?int`
- `getString(array $array, string $key, ?string $default = null): ?string`
- `hasValue(array $array, string $key): bool`

## 2. EntryRepository Changes

### BC Break: queryByFilterArray Method

**Location**: `src/Repository/EntryRepository.php`

**Change**: Filter array values are now properly type-checked before use.

**Before**:
```php
if (isset($arFilter['customer']) && null !== $arFilter['customer']) {
    $queryBuilder->setParameter('customer', (int) $arFilter['customer']);
}
```

**After**:
```php
$customerId = ArrayTypeHelper::getInt($arFilter, 'customer');
if ($customerId !== null) {
    $queryBuilder->setParameter('customer', $customerId);
}
```

**Impact**: 
- More robust type handling
- Eliminates potential runtime errors from invalid type casting
- No changes needed for callers passing correct types

### BC Break: Removed Redundant instanceof Checks

**Change**: Removed unnecessary `instanceof Entry` checks in repository methods.

**Reason**: Doctrine guarantees return types when querying from Entity repositories.

**Before**:
```php
return array_values(array_filter($result, fn($entry) => $entry instanceof Entry));
```

**After**:
```php
return $result;  // Already guaranteed to be Entry[]
```

## 3. Test Suite Changes

### BC Break: EntrySaveDto Test Error Handling

**Location**: `tests/Dto/EntrySaveDtoTest.php`

**Change**: Added proper error handler restoration in tearDown method.

**Before**:
```php
protected function tearDown(): void
{
    parent::tearDown();
    self::ensureKernelShutdown();
}
```

**After**:
```php
protected function tearDown(): void
{
    restore_exception_handler();
    restore_error_handler();
    parent::tearDown();
}
```

**Impact**: Tests may be marked as "risky" but all assertions pass correctly.

## 4. Validation Constraints

### BC Break: Type-Safe DTO Validation

**Affected Files**:
- `src/Validator/Constraints/UniqueActivityNameValidator.php`
- `src/Validator/Constraints/UniqueTeamNameValidator.php`
- `src/Validator/Constraints/UniqueTicketSystemNameValidator.php`

**Change**: Validators now properly handle mixed types from DTOs.

**Migration**: Ensure DTOs passed to validators have proper type annotations.

## 5. Service Layer Changes

### BC Break: ResponseFactory Type Annotations

**Location**: `src/Service/Response/ResponseFactory.php`

**Change**: Added proper PHPDoc type annotations for array parameters.

**Impact**: Stricter type checking at analysis time, no runtime changes.

### BC Break: TokenEncryptionService Type Handling

**Location**: `src/Service/Security/TokenEncryptionService.php`

**Change**: Improved type handling for encryption key parameter.

**Impact**: More robust parameter validation.

## Migration Guide

### For Application Developers

1. **Update Filter Arrays**: Ensure all filter arrays passed to repository methods contain properly typed values:
   ```php
   // Ensure integer IDs
   $filter = [
       'customer' => (int) $customerId,
       'project' => (int) $projectId,
   ];
   ```

2. **Review Custom Validators**: If you have custom validators extending the modified validators, ensure they handle the new type checking patterns.

3. **Test Suite Updates**: If you have tests extending `KernelTestCase`, consider adding error handler restoration in tearDown methods.

### For Library Consumers

1. **Type Declarations**: Ensure all method parameters and return types match the new stricter type declarations.

2. **Array Helper Usage**: Consider using `ArrayTypeHelper` for your own mixed array handling:
   ```php
   use App\Service\TypeSafety\ArrayTypeHelper;
   
   $value = ArrayTypeHelper::getInt($mixedArray, 'key');
   ```

## Benefits

1. **Type Safety**: Eliminates runtime type errors from unsafe casting
2. **Static Analysis**: PHPStan Level 9 compliance ensures code quality
3. **Maintainability**: Clearer type contracts make code easier to understand
4. **Performance**: Removed redundant checks improve execution speed
5. **Reliability**: Proper type handling prevents edge case failures

## Compatibility

- **PHP Version**: Requires PHP 8.1+ (for readonly properties in ArrayTypeHelper)
- **Symfony Version**: Compatible with Symfony 6.4+
- **Doctrine Version**: Compatible with Doctrine ORM 2.14+

## Support

For questions or issues related to these breaking changes, please:
1. Check the migration guide above
2. Review the updated PHPDoc annotations
3. Run `make stan` to identify any type issues in your code
4. Create an issue in the project repository if needed