# Breaking Changes - PHPStan Level 9 Fixes

## Overview

This document outlines the breaking changes introduced while fixing PHPStan static analysis errors to achieve level 9 compliance. These changes modernize the codebase with proper type safety and follow Symfony 7.3 + Doctrine ORM 3 patterns.

## Summary of Changes

- **DatabaseResultDto**: New DTO pattern for type-safe database result transformations
- **EntryRepository**: Modernized repository with proper return types and DTO usage
- **ResponseFactory**: Added proper generic type annotations
- **Validator Constraints**: Updated all validators to use proper DTO type checking
- **EventSubscriber**: Reviewed and confirmed modern architecture
- **TokenEncryptionService**: Reviewed and confirmed secure implementation

## Breaking Changes by Component

### 1. EntryRepository Changes

**File**: `src/Repository/EntryRepository.php`

#### BC Break: Method Return Types

**Before**:
```php
// Methods returned mixed arrays with unsafe casting
$line['user'] = (int) $line['user']; // Unsafe cast from mixed
return $results; // No type safety
```

**After**:
```php
// Uses DatabaseResultDto for type-safe transformations
$transformedLine = DatabaseResultDto::transformEntryRow($line);
return $results; // Properly typed with @return Entry[]
```

**Impact**: 
- Any code directly accessing raw database results will need to handle the new DTO transformation
- Return types are now strictly enforced - methods return `Entry[]` instead of mixed arrays

**Migration**: 
- Update any code that depends on direct database result manipulation
- Use `DatabaseResultDto::transformEntryRow()` for safe type casting

#### BC Break: Removed Redundant Checks

**Before**:
```php
if ($entry instanceof Entry) { // Always true check
    // Process entry
}
```

**After**:
```php
// Removed redundant instanceof checks
// Direct processing since Doctrine guarantees Entry objects
```

**Impact**: 
- Code that relied on these redundant checks may need adjustment
- No functional impact as checks were always true

### 2. DatabaseResultDto (New Component)

**File**: `src/Dto/DatabaseResultDto.php` (NEW)

This is a new component that provides type-safe database result transformations:

```php
final readonly class DatabaseResultDto
{
    public static function transformEntryRow(array $row): array
    public static function transformScopeRow(array $row, string $scope): array
    private static function safeString(mixed $value, string $default = ''): string
    private static function safeInt(mixed $value, int $default = 0): int
    public static function safeDateTime(mixed $value, string $default = ''): string
}
```

**Impact**: 
- New dependency for any code working with database results
- Centralized type-safe casting eliminates direct mixed-to-int casting

**Migration**:
- Use `DatabaseResultDto::transformEntryRow()` instead of manual casting
- Use helper methods for safe type conversions

### 3. ResponseFactory Type Annotations

**File**: `src/Service/Response/ResponseFactory.php`

#### BC Break: Stricter Type Annotations

**Before**:
```php
public function success(array $data = [], ?string $alert = null): JsonResponse
public function validationError(array $errors): Error
public function paginated(array $items, ...): JsonResponse
public function withMetadata(array $data, array $metadata): JsonResponse
```

**After**:
```php
/**
 * @param array<string, mixed> $data
 */
public function success(array $data = [], ?string $alert = null): JsonResponse

/**
 * @param array<string, string> $errors
 */
public function validationError(array $errors): Error

/**
 * @param list<mixed> $items
 */
public function paginated(array $items, ...): JsonResponse

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $metadata
 */
public function withMetadata(array $data, array $metadata): JsonResponse
```

**Impact**: 
- Static analysis will enforce array structure more strictly
- Runtime behavior unchanged, but type contracts are clearer

**Migration**:
- Ensure arrays passed to these methods match the expected structure
- `validationError()` now expects `array<string, string>` specifically

### 4. Validator Constraints Changes

**Files**: All `src/Validator/Constraints/*Validator.php`

#### BC Break: Strict DTO Type Checking

**Before**:
```php
// Unsafe property access on mixed objects
$dto = $this->context->getObject();
$customerId = $dto->id ?? 0; // Mixed property access
```

**After**:
```php
// Type-safe DTO checking
$dto = $this->context->getObject();
if ($dto instanceof \App\Dto\CustomerSaveDto) {
    $customerId = $dto->id; // Type-safe access
}
```

**Affected Validators**:
- `UniqueCustomerNameValidator`
- `UniqueProjectNameForCustomerValidator` 
- `UniqueUsernameValidator`
- `UniqueUserAbbrValidator`
- `CustomerTeamsRequiredValidator`
- `ContractDatesValidValidator`

**Impact**: 
- Validators now require specific DTO types
- Validation will fail if wrong DTO type is passed
- Eliminates potential runtime errors from mixed property access

**Migration**:
- Ensure DTOs are properly typed when used with validators
- Update any custom validation logic to use proper DTO types

### 5. Validation System Architecture

#### BC Break: Removed Legacy Validation Services

**Deleted Files**:
- `src/Service/Validation/CustomerValidator.php`
- `src/Service/Validation/ProjectValidator.php`
- `src/Service/Validation/UserValidator.php`
- `src/Service/Validation/ValidationException.php`
- `src/Service/Validation/ValidationResult.php`
- `src/Service/Validation/ValidationService.php`

**Impact**: 
- Legacy validation services are no longer available
- Applications must use Symfony's built-in validation with DTOs

**Migration**:
- Replace legacy validation service calls with Symfony Validator component
- Use DTOs with constraint annotations instead of service-based validation

## Compatibility Impact Assessment

### Low Risk Changes
- **DatabaseResultDto**: New component, no existing code conflicts
- **ResponseFactory annotations**: PHPDoc only, no runtime changes
- **TokenEncryptionService**: No changes, already well-architected

### Medium Risk Changes  
- **EntryRepository return types**: May affect code expecting mixed arrays
- **Validator constraints**: Changes validation error behavior with wrong DTO types

### High Risk Changes
- **Removed validation services**: Complete architectural change
- **Strict DTO typing**: May break existing validation workflows

## Testing Recommendations

1. **Repository Tests**: Verify all EntryRepository methods return proper types
2. **Validation Tests**: Test all validators with correct DTO types
3. **Integration Tests**: Test complete workflows with new validation system
4. **Response Tests**: Verify ResponseFactory maintains API contract

## Rollback Strategy

If issues arise:

1. **Keep DatabaseResultDto**: Can be safely reverted to manual casting
2. **EntryRepository**: Restore unsafe casting if needed (not recommended)
3. **Validators**: Can temporarily remove instanceof checks with warning
4. **Validation Services**: Cannot easily restore - require forward migration

## Modern Architecture Benefits

- **Type Safety**: PHPStan Level 9 compliance eliminates runtime type errors
- **Maintainability**: Clear type contracts improve code understanding
- **Performance**: Eliminates unnecessary type checks and improves caching
- **Future-Proof**: Follows Symfony 7.3 and Doctrine ORM 3 best practices
- **Developer Experience**: Better IDE support and error prevention

## Conclusion

These changes represent a significant modernization of the codebase, bringing it to current PHP and Symfony standards. While there are breaking changes, they eliminate potential runtime errors and improve long-term maintainability. The migration effort is justified by the increased type safety and compliance with modern frameworks.