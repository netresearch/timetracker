# Xdebug Development Setup

How to set up and use Xdebug for debugging PHP code in the TimeTracker
development environment.

## Overview

Xdebug is installed in the `dev` Docker stage (see [Dockerfile](../Dockerfile),
inherited by the `e2e` stage) and provides:

- Step debugging with IDE integration
- Enhanced error reporting and stack traces
- Code coverage analysis

**It is off by default**: [docker/php/xdebug.ini](../docker/php/xdebug.ini)
sets `xdebug.mode=off`, so there is no runtime overhead. Activate it per run
via the `XDEBUG_MODE` environment variable:

```bash
XDEBUG_MODE=debug     # step debugging (IDE)
XDEBUG_MODE=coverage  # test coverage (used by make coverage)
make test-debug       # runs the test suite with XDEBUG_MODE=debug,develop
```

The `production` image stage does not include Xdebug at all.

## Configuration

The baked-in settings ([docker/php/xdebug.ini](../docker/php/xdebug.ini)):

```ini
xdebug.mode=off                            ; enable via XDEBUG_MODE env var
xdebug.start_with_request=yes              ; auto-start once a mode is active
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
xdebug.discover_client_host=true
xdebug.show_error_trace=1
xdebug.show_exception_trace=1
```

Because `start_with_request=yes` is set, a session starts automatically for
every request as soon as `XDEBUG_MODE=debug` is active — no browser cookie or
`XDEBUG_SESSION` parameter is required (an "Xdebug helper" browser extension
still works if you prefer selective triggering).

To enable debugging for the whole dev container, set the variable on the
service, e.g. in a compose override:

```yaml
services:
  app-dev:
    environment:
      - XDEBUG_MODE=debug,develop
```

## IDE Setup

### PhpStorm

1. **Settings** → **PHP** → **Debug**: Xdebug port `9003`, allow external
   connections
2. **Settings** → **PHP** → **Servers**: add a server
   - Name: `timetracker-dev`
   - Host: `localhost`, Port: `8765`
   - Path mapping: project root → `/var/www/html`
3. Start "Listen for PHP Debug Connections"

### VS Code

Install the "PHP Debug" extension and create `.vscode/launch.json`:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003,
            "pathMappings": {
                "/var/www/html": "${workspaceRoot}"
            }
        }
    ]
}
```

## Usage

### Web debugging

1. Start your IDE debugger (listen for connections)
2. Set breakpoints
3. Make sure the container runs with `XDEBUG_MODE=debug` (see above)
4. Open http://localhost:8765 — the session starts automatically

### CLI debugging

```bash
# Debug a console command
docker compose exec -e XDEBUG_MODE=debug app-dev bin/console tt:sync-subtickets

# Debug specific PHPUnit tests
docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=debug app-dev \
  ./bin/phpunit --filter=testMethodName

# Or simply: whole suite with Xdebug
make test-debug
```

### Verify the installation

```bash
docker compose exec app-dev php bin/test-xdebug.php
```

## Code coverage

```bash
make coverage   # XDEBUG_MODE=coverage → HTML report in var/coverage/index.html
```

## Troubleshooting

1. **Xdebug not connecting to IDE**
   - Is the container actually running with `XDEBUG_MODE=debug`? Check with
     `docker compose exec app-dev php -i | grep xdebug.mode`
   - Check the IDE is listening on port 9003
   - Verify `host.docker.internal` resolves from the container
2. **Breakpoints not hit**
   - Check the path mapping (`/var/www/html` → project root)
3. **Slow tests**
   - Keep `XDEBUG_MODE=off` (the default in all `make test*` targets except
     `test-debug` and `coverage`)

Enable Xdebug's own log for connection issues (in
[docker/php/xdebug.ini](../docker/php/xdebug.ini)):

```ini
xdebug.log_level=7
xdebug.log=/var/log/xdebug.log
```

```bash
docker compose exec app-dev tail -f /var/log/xdebug.log
```
