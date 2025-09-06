#!/bin/bash

# Automated Rollback Script for Legacy Linting Tools Removal
# This script reverts all changes made during legacy tool removal

set -euo pipefail

echo "🔄 ROLLBACK: Reverting legacy linting tool removal..."

# Verify we're on the correct branch
current_branch=$(git branch --show-current)
if [ "$current_branch" != "remove/legacy-linting-tools" ]; then
    echo "❌ Error: Must be on 'remove/legacy-linting-tools' branch to rollback"
    exit 1
fi

# Check if backup directory exists
if [ ! -d "backup-legacy-configs" ]; then
    echo "❌ Error: Backup directory 'backup-legacy-configs' not found"
    echo "Cannot perform rollback without backups"
    exit 1
fi

echo "📂 Restoring configuration files from backup..."

# Restore original configuration files
cp backup-legacy-configs/composer.json.backup composer.json
cp backup-legacy-configs/composer.lock.backup composer.lock
cp backup-legacy-configs/psalm.xml.backup psalm.xml
cp backup-legacy-configs/psalm-baseline.xml.backup psalm-baseline.xml
cp backup-legacy-configs/phpcs.xml.backup phpcs.xml
cp backup-legacy-configs/php-cs-fixer.php.backup .php-cs-fixer.php

echo "🧹 Removing modern tool configuration files..."

# Remove new modern tool configurations (if they were created)
[ -f "pint.json" ] && rm pint.json
[ -f "phpat.php" ] && rm phpat.php  
[ -f "phpstan-phpat.neon" ] && rm phpstan-phpat.neon

echo "📝 Git status after rollback:"
git status

echo "✅ Rollback completed successfully!"
echo ""
echo "Next steps:"
echo "1. Run 'git diff' to verify changes"
echo "2. If satisfied, commit the rollback: git commit -m 'rollback: restore legacy linting tools'"
echo "3. Switch back to original branch: git checkout fix/authorization-bypasses"
echo "4. Delete rollback branch: git branch -D remove/legacy-linting-tools"
echo ""
echo "Legacy tools restored:"
echo "- PHP_CodeSniffer (phpcs.xml)"
echo "- PHP-CS-Fixer (.php-cs-fixer.php)"
echo "- Psalm (psalm.xml + 1,882-line baseline)"