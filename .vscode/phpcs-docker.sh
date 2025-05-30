#!/bin/sh
# This script executes phpcs inside the Docker container,
# forwarding any arguments passed by the VS Code extension.
docker compose run --rm app bin/phpcs "$@"
