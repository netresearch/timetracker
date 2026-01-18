#!/bin/sh
# PHP-FPM healthcheck script
#
# Checks if PHP-FPM is responding to FastCGI requests.
# Used by Docker HEALTHCHECK instruction.

set -e

# Check if php-fpm is running
if ! pgrep -x "php-fpm" > /dev/null 2>&1; then
    echo "php-fpm process not running"
    exit 1
fi

# Check if php-fpm socket/port is responding
# Using cgi-fcgi if available, otherwise just check process
if command -v cgi-fcgi > /dev/null 2>&1; then
    SCRIPT_NAME=/ping \
    SCRIPT_FILENAME=/ping \
    REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 > /dev/null 2>&1
else
    # Fallback: just verify the process is healthy
    php-fpm -t > /dev/null 2>&1
fi

exit 0
