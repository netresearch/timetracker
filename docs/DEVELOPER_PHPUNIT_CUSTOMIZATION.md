# PHPUnit Local Customization Guide

The project uses [phpunit.xml.dist](../phpunit.xml.dist) (PHPUnit 13) as the
default test configuration. You can create a local `phpunit.xml` — it is
gitignored — to override settings for your environment. PHPUnit prefers
`phpunit.xml` over `phpunit.xml.dist` automatically.

## Creating your local configuration

```bash
cp phpunit.xml.dist phpunit.xml
```

Then modify only what you need. Never force-add `phpunit.xml` to git; if
several developers need the same change, update `phpunit.xml.dist` instead.

## Common local customizations

### Database connection

The default test database is the `db_unittest` container
([.env.test](../.env.test)). If you run tests against a different database:

```xml
<php>
    <env name="DATABASE_URL" value="mysql://unittest:unittest@127.0.0.1:3306/unittest?serverVersion=mariadb-12.1.2&amp;charset=utf8mb4"/>
</php>
```

### Memory and execution limits

```xml
<php>
    <ini name="memory_limit" value="512M"/>
    <ini name="max_execution_time" value="60"/>
</php>
```

### Debugging and verbosity

```xml
<phpunit
    displayDetailsOnTestsThatTriggerErrors="true"
    displayDetailsOnTestsThatTriggerWarnings="true"
    displayDetailsOnTestsThatTriggerDeprecations="true"
    stopOnFailure="true"
>
```

There is also a ready-made verbose config:
`./bin/phpunit --configuration=config/testing/phpunit.xml.verbose`
(or `make test-verbose`).

### Code coverage output

```xml
<coverage>
    <report>
        <html outputDirectory="var/coverage"/>
        <text outputFile="php://stdout" showOnlySummary="true"/>
    </report>
</coverage>
```

Requires a coverage driver: run with `XDEBUG_MODE=coverage` (`make coverage`
does this for you).

### Focusing on specific tests

Usually `--filter` or a path argument is enough:

```bash
./bin/phpunit --filter=EntrySaveDtoTest
./bin/phpunit tests/Service/
```

For longer stretches of work you can define your own suite locally:

```xml
<testsuites>
    <testsuite name="current-work">
        <directory>tests/Service</directory>
    </testsuite>
</testsuites>
```

## Example local phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- Local development configuration — not committed -->
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/13.1/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    stopOnFailure="true"
    executionOrder="depends,defects"
    cacheDirectory=".phpunit.cache"
    displayDetailsOnTestsThatTriggerErrors="true"
    displayDetailsOnTestsThatTriggerWarnings="true"
    displayDetailsOnTestsThatTriggerDeprecations="true"
>
    <coverage/>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>

    <testsuites>
        <testsuite name="current-work">
            <directory>tests/Service</directory>
        </testsuite>
    </testsuites>

    <php>
        <server name="APP_ENV" value="test" force="true"/>
        <server name="KERNEL_CLASS" value="App\Kernel"/>
        <ini name="memory_limit" value="1G"/>
        <!-- Fail on deprecations while modernizing code -->
        <env name="SYMFONY_DEPRECATIONS_HELPER" value="strict"/>
    </php>
</phpunit>
```

## Troubleshooting

If tests pass in CI but fail locally (or vice versa), diff:

1. Your local `phpunit.xml` (if present) against `phpunit.xml.dist`
2. `DATABASE_URL` — CI and `make test` use the `db_unittest` container
3. Memory limits and `XDEBUG_MODE` (Xdebug slows tests down; keep it `off`
   unless debugging or collecting coverage)
