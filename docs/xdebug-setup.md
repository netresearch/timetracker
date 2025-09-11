# Xdebug Development Setup

This document explains how to set up and use Xdebug for debugging PHP code in the TimeTracker development environment.

## Overview

Xdebug is installed in the `devbox` Docker stage and provides:
- Step debugging with IDE integration
- Enhanced error reporting and stack traces
- Code coverage analysis capabilities
- Performance profiling (optional)

## Configuration

### Docker Configuration

The Xdebug configuration is automatically applied when using the development container:

```bash
# Build development container with Xdebug
docker-compose build app-dev

# Start development environment
COMPOSE_PROFILES=dev docker-compose up -d
```

### IDE Setup

#### PhpStorm
1. Go to **Settings** → **Languages & Frameworks** → **PHP** → **Debug**
2. Set Xdebug port to `9003`
3. Enable "Can accept external connections"
4. Go to **Settings** → **Languages & Frameworks** → **PHP** → **Servers**
5. Add a new server:
   - Name: `timetracker-dev`
   - Host: `localhost`
   - Port: `8765`
   - Debugger: `Xdebug`
   - Use path mappings:
     - Project root → `/var/www/html`

#### VS Code
1. Install the "PHP Debug" extension
2. Create `.vscode/launch.json`:
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

### Web Debugging

1. Start your IDE debugger (listen for connections)
2. Set breakpoints in your PHP code
3. Add `XDEBUG_SESSION=PHPSTORM` to your browser URL:
   ```
   http://localhost:8765/?XDEBUG_SESSION=PHPSTORM
   ```
4. Or use a browser extension like "Xdebug helper"

### CLI Debugging

```bash
# Debug a CLI script
docker-compose exec app-dev php -dxdebug.start_with_request=yes bin/console debug:config

# Debug a specific PHP file
docker-compose exec app-dev php -dxdebug.start_with_request=yes bin/test-xdebug.php
```

### Testing Xdebug Installation

```bash
# Test Xdebug is working
docker-compose exec app-dev php bin/test-xdebug.php
```

## Configuration Details

### Xdebug Settings

The development container uses these Xdebug settings:

```ini
; Enable debugging and development features
xdebug.mode=debug,develop

; Auto-start debugging for requests
xdebug.start_with_request=yes

; Connect to host machine IDE
xdebug.client_host=host.docker.internal
xdebug.client_port=9003

; Auto-discover IDE
xdebug.discover_client_host=true

; Enhanced error display
xdebug.show_error_trace=1
xdebug.show_exception_trace=1
```

### Environment Variables

You can override Xdebug behavior with environment variables:

```yaml
# In docker-compose.yml
environment:
  - XDEBUG_MODE=debug,develop
  - XDEBUG_CONFIG=client_host=host.docker.internal client_port=9003
```

## Troubleshooting

### Common Issues

1. **Xdebug not connecting to IDE**
   - Check IDE is listening on port 9003
   - Verify `host.docker.internal` resolves correctly
   - Try disabling firewall temporarily

2. **Breakpoints not working**
   - Ensure path mappings are correct
   - Check `XDEBUG_SESSION` parameter is set
   - Verify Xdebug mode includes "debug"

3. **Performance impact**
   - Xdebug only runs in development container
   - Disable when not debugging: set `xdebug.mode=off`

### Debug Logs

Enable Xdebug logging for connection troubleshooting:

```bash
# Add to docker/php/xdebug.ini
xdebug.log_level=7
xdebug.log=/var/log/xdebug.log

# View logs
docker-compose exec app-dev tail -f /var/log/xdebug.log
```

## Performance Considerations

- Xdebug adds overhead - only enabled in development
- Production containers (`runtime` stage) do not include Xdebug
- APCu cache still works efficiently with Xdebug enabled
- For production-like testing, use `runtime` stage without Xdebug

## Integration with Other Tools

### PHPUnit Debugging
```bash
# Debug PHPUnit tests
docker-compose exec app-dev php -dxdebug.start_with_request=yes vendor/bin/phpunit --filter=testMethod
```

### Symfony Console Debugging
```bash
# Debug Symfony commands
docker-compose exec app-dev php -dxdebug.start_with_request=yes bin/console app:command
```

### Code Coverage
```bash
# Generate code coverage with Xdebug
docker-compose exec app-dev php -dxdebug.mode=coverage vendor/bin/phpunit --coverage-html coverage/
```