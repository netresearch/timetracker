#!/bin/bash

# Fix Symfony 7.3.3 validator bug where framework bundle references deprecated ExpressionLanguageSyntaxValidator.php
# This file was deprecated in favor of ExpressionSyntaxValidator.php but framework bundle still references it

VALIDATOR_DIR="/var/www/html/vendor/symfony/validator/Constraints"
SOURCE_FILE="$VALIDATOR_DIR/ExpressionSyntaxValidator.php"
TARGET_FILE="$VALIDATOR_DIR/ExpressionLanguageSyntaxValidator.php"

if [ -f "$SOURCE_FILE" ] && [ ! -f "$TARGET_FILE" ]; then
    echo "Fixing Symfony validator bug: Creating missing ExpressionLanguageSyntaxValidator.php"
    cp "$SOURCE_FILE" "$TARGET_FILE"
    echo "Fixed: Created $TARGET_FILE"
else
    echo "Symfony validator fix not needed or already applied"
fi