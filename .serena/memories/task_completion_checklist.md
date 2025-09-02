# Task Completion Checklist

## Before Marking a Task Complete

### 1. Code Quality Checks ✓
Run ALL quality checks before considering a task done:
```bash
# Run comprehensive check
make check-all
# Or individually:
make stan        # PHPStan level 8 must pass
make psalm       # Psalm analysis must pass
make cs-check    # Coding standards must pass
make twig-lint   # Twig templates must be valid
```

### 2. Test Suite ✓
Ensure all tests pass:
```bash
# Run full test suite
make test
# Or for faster iteration:
composer test:fast
# Check specific changes didn't break controllers:
composer test:controller
```

### 3. Auto-Fix Available Issues ✓
If any checks fail, try auto-fixing:
```bash
make fix-all
# Or individually:
make cs-fix          # Fix code style
composer psalm:fix   # Fix Psalm issues
composer rector      # Apply Rector rules
```

### 4. Clear Caches ✓
Clear caches to ensure changes take effect:
```bash
make cache-clear
# For test environment:
docker compose exec -T app sh -c 'APP_ENV=test php bin/console cache:clear'
```

### 5. Database Migrations ✓
If database changes were made:
```bash
# Generate migration if needed
docker compose exec app bin/console make:migration
# Review the migration file
# Run migrations
make db-migrate
```

### 6. Documentation ✓
- Update relevant documentation if APIs changed
- Add/update DocBlocks for complex methods
- Update README if setup/usage changed

### 7. Git Hygiene ✓
```bash
# Ensure on feature branch
git branch
# Review changes
git diff
# Stage and commit with meaningful message
git add .
git commit -m "type: description"
```

### 8. Final Validation ✓
Before pushing:
1. Restart containers if service configs changed
2. Test the actual feature in browser/API
3. Check no debug code remains (var_dump, dd, console.log)
4. Ensure no TODO comments for required functionality

## Quick Command Sequence
```bash
# The essential sequence before marking complete:
make check-all && make test
# If all passes, you're ready!
```

## Container/Service Changes
If you modified services, dependency injection, or container configuration:
```bash
# Restart containers
make restart
# Or just clear and warm cache
make cache-clear
```

## Common Issues to Check
- [ ] No hardcoded values that should be config
- [ ] No commented-out code blocks
- [ ] No skipped/disabled tests
- [ ] Proper error handling in place
- [ ] Input validation implemented
- [ ] Security considerations addressed
- [ ] Performance impact considered

## Definition of Done
A task is ONLY complete when:
✅ All automated checks pass (stan, psalm, phpcs, tests)
✅ Feature works as specified
✅ Code follows project conventions
✅ Tests cover new functionality
✅ No regression in existing features
✅ Documentation updated if needed
✅ Peer review passed (if applicable)