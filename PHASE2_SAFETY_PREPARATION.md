# Phase 2: Safety Preparation for Legacy Tool Removal

**Date**: 2025-09-06  
**Branch**: `remove/legacy-linting-tools`  
**Purpose**: Comprehensive backup and rollback strategy before removing legacy linting tools

## Current Project State

### Branch Information
- **Active Branch**: `remove/legacy-linting-tools` (safety branch)
- **Parent Branch**: `fix/authorization-bypasses`
- **Uncommitted Changes**: composer.json, composer.lock modifications

### Legacy Tools to Remove
1. **PHP_CodeSniffer** (`squizlabs/php_codesniffer: ^3.13`)
   - Configuration: `phpcs.xml`
   - Scripts: `cs-check`, `cs-check:pint`

2. **PHP-CS-Fixer** (`friendsofphp/php-cs-fixer: *`)
   - Configuration: `.php-cs-fixer.php`
   - Scripts: `cs-fix`, `cs-fix:pint`

3. **Psalm Baseline** (1,882 suppressed issues)
   - Configuration: `psalm.xml` (with baseline reference)
   - Baseline: `psalm-baseline.xml` (1,882 lines)
   - Scripts: `psalm`, `psalm:fix`

### Modern Tools (Keeping)
1. **PHPStan** (`phpstan/phpstan: ^2.1`) ✅
   - Configuration: `phpstan.neon` 
   - Current errors: 36
   - Status: Working

2. **Laravel Pint** (`laravel/pint: ^1.18`) ✅
   - Configuration: `pint.json`
   - Status: Timeout (needs investigation)

3. **PHPat** (`phpat/phpat: ^0.11`) ✅
   - Configuration: `phpat.php`
   - Current errors: 84+ (some config issues)
   - Status: Partially working

## Backup Strategy

### Files Backed Up
All critical configuration files have been backed up to `backup-legacy-configs/`:

```bash
backup-legacy-configs/
├── composer.json.backup          # Package dependencies
├── composer.lock.backup          # Lock file
├── psalm.xml.backup              # Psalm configuration
├── psalm-baseline.xml.backup     # 1,882 suppressed issues
├── phpcs.xml.backup              # PHP_CodeSniffer rules
└── php-cs-fixer.php.backup       # PHP-CS-Fixer rules
```

### Rollback Script
- **Script**: `rollback-legacy-removal.sh`
- **Function**: Automated restoration of all legacy configurations
- **Safety**: Validates branch and backup existence before proceeding

## Current Tool Status

### Working Tools ✅
- **PHPStan**: 36 errors (manageable)
- **Modern CI Pipeline**: Using Docker + Makefile

### Problematic Tools ⚠️
- **Psalm**: CRASHED due to corrupted baseline XML (line 129)
- **PHPat**: 84+ errors (some configuration issues)  
- **Pint**: Timeout (likely extensive formatting needed)

### Legacy Tools Status 📦
- **PHP_CodeSniffer**: Referenced in CI workflow
- **PHP-CS-Fixer**: Still in composer.json
- **Psalm Baseline**: 1,882 suppressed issues (technical debt)

## Risk Assessment

### Low Risk ✅
- Modern toolchain (PHPStan + Pint + PHPat) is installed and partially functional
- Complete backup strategy implemented
- Safety branch created for isolated changes
- Automated rollback available

### Medium Risk ⚠️
- Pint configuration may need adjustment (currently timing out)
- PHPat architectural rules need debugging
- CI workflow needs updating after removal

### High Risk 🚨
- Psalm baseline removal exposes 1,882 suppressed issues
- Legacy tools removal may break existing developer workflows
- CI pipeline depends on legacy tools

## Success Criteria

### Pre-Removal Validation
- [ ] Modern toolchain runs without crashes
- [ ] Pint timeout issue resolved
- [ ] PHPat configuration errors fixed
- [ ] CI workflow validated with modern tools only

### Post-Removal Validation
- [ ] All modern tools run successfully
- [ ] Error counts remain manageable (<50 per tool)
- [ ] CI pipeline passes with modern tools
- [ ] No functionality regressions

## Rollback Triggers

Immediate rollback if:
1. Modern toolchain completely fails
2. CI pipeline breaks critically
3. Developer productivity severely impacted
4. Error count exceeds manageable threshold (>200)

## Next Steps

### Phase 2A: Fix Modern Toolchain Issues
1. Debug Pint timeout issue
2. Fix PHPat configuration errors
3. Validate all tools work correctly

### Phase 2B: Update CI/CD Pipeline
1. Remove legacy tool references from `.github/workflows/ci.yml`
2. Update Makefile commands
3. Test updated pipeline

### Phase 3: Execute Removal
1. Remove legacy dependencies from composer.json
2. Delete legacy configuration files
3. Update composer scripts
4. Run comprehensive validation

## Validation Commands

```bash
# Test modern toolchain
make stan                    # PHPStan
docker compose run --rm app-dev composer analyze:arch  # PHPat
docker compose run --rm app-dev composer cs-check:pint # Pint

# Rollback if needed
./rollback-legacy-removal.sh

# Validation after removal
composer check:modern        # Modern tool validation
make test                   # Full test suite
```

## File Inventory

### Configuration Files
- `phpstan.neon` - PHPStan level 9 configuration
- `pint.json` - Laravel Pint formatting rules
- `phpat.php` - Architectural testing rules
- `phpstan-phpat.neon` - PHPStan config for PHPat

### Legacy Files (To Remove)
- `psalm.xml` - Psalm configuration
- `psalm-baseline.xml` - 1,882 suppressed issues  
- `phpcs.xml` - PHP_CodeSniffer ruleset
- `.php-cs-fixer.php` - PHP-CS-Fixer configuration

### Backup Directory
- `backup-legacy-configs/` - Complete configuration backup
- `rollback-legacy-removal.sh` - Automated rollback script

---

**Status**: ✅ READY FOR PHASE 2A (Modern Toolchain Fixes)  
**Safety Level**: 🟢 HIGH - Complete rollback capability available