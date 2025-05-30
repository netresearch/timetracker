#!/bin/bash

# Run PHPUnit with coverage and increased memory limit through Docker
docker compose run --rm -e APP_ENV=test -e PHP_INI_MEMORY_LIMIT=512M app php -d memory_limit=512M bin/phpunit tests --filter "$@" --coverage-text
