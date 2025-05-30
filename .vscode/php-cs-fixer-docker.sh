#!/bin/sh
# This script executes php-cs-fixer inside the Docker container,
# forwarding any arguments passed by the VS Code extension.
# Note: We need to handle the --path-mode argument explicitly if passed.
# Docker mounts the current directory, so paths are relative to the project root.

COMMAND="bin/php-cs-fixer"
ARGS=()
PATH_MODE_OVERRIDE=false

# Parse arguments to find --path-mode=override
while [ $# -gt 0 ]; do
    case "$1" in
        --path-mode=*)
            if [ "$1" = "--path-mode=override" ]; then
                PATH_MODE_OVERRIDE=true
            fi
            # Don't pass --path-mode to the container, paths are already relative
            shift
            ;;
        *)
            ARGS+=("$1")
            shift
            ;;
    esac
done

# If --path-mode=override was passed, the last argument is likely a temporary file path.
# We need to figure out the *original* file path relative to the project root.
# This is complex and depends on how the extension creates the temp file.
# A simpler approach for now is to just run the command without the temp path,
# assuming it's meant to operate on the whole project or configured paths.
# A more robust solution might involve inspecting the .php-cs-fixer.dist.php config.

# For now, just pass the filtered arguments
docker compose run --rm app $COMMAND "${ARGS[@]}"
