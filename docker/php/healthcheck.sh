#!/bin/sh
# PHP-FPM healthcheck script
#
# Checks if PHP-FPM is running and healthy.
# Used by Docker HEALTHCHECK instruction.

set -e

# Check if php-fpm master process is running
if ! pgrep -x "php-fpm" > /dev/null 2>&1; then
    echo "php-fpm process not running"
    exit 1
fi

# Verify php-fpm configuration is valid
php-fpm -t > /dev/null 2>&1

exit 0
