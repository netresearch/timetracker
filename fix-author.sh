#!/bin/bash

# Script to fix Git author information
# Changes "Automation <dev@local>" to "Sebastian Mendel <info@sebastianmendel.de>"

echo "Fixing Git author information..."
echo "WARNING: This will rewrite Git history!"
echo ""

# Show current commits with incorrect author
echo "Commits to be fixed:"
git log --oneline --author="Automation" --author="dev@local"
echo ""

# Ask for confirmation
read -p "Do you want to proceed? This will rewrite history! (y/N): " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

# Create a backup branch before rewriting
BACKUP_BRANCH="backup-before-author-fix-$(date +%Y%m%d-%H%M%S)"
echo "Creating backup branch: $BACKUP_BRANCH"
git branch "$BACKUP_BRANCH"

# Use git filter-branch to fix the author
echo "Rewriting history to fix author information..."

git filter-branch --env-filter '
OLD_EMAIL="dev@local"
CORRECT_NAME="Sebastian Mendel"
CORRECT_EMAIL="info@sebastianmendel.de"

if [ "$GIT_COMMITTER_EMAIL" = "$OLD_EMAIL" ]; then
    export GIT_COMMITTER_NAME="$CORRECT_NAME"
    export GIT_COMMITTER_EMAIL="$CORRECT_EMAIL"
fi
if [ "$GIT_AUTHOR_EMAIL" = "$OLD_EMAIL" ]; then
    export GIT_AUTHOR_NAME="$CORRECT_NAME"
    export GIT_AUTHOR_EMAIL="$CORRECT_EMAIL"
fi
' --tag-name-filter cat -- --branches --tags

echo ""
echo "Author fix complete!"
echo ""
echo "Verification - commits after fix:"
git log --format="%h %an <%ae>" | head -10
echo ""
echo "If everything looks good, you can:"
echo "1. Force push to remote: git push --force-with-lease origin main"
echo "2. Delete the backup branch: git branch -d $BACKUP_BRANCH"
echo "3. Clean up refs: rm -rf .git/refs/original/"
echo ""
echo "If something went wrong, restore from backup:"
echo "git reset --hard $BACKUP_BRANCH"