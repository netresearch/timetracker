# PHPUnit Local Customization Guide

## Overview
The project uses `phpunit.xml.dist` as the default test configuration. Developers can create a local `phpunit.xml` file (which is gitignored) to override settings for their specific environment.

## Common Local Customizations

### 1. **Database Configuration**
Developers might use different database credentials or hosts:
```xml
<php>
    <env name="DATABASE_URL" value="mysql://myuser:mypass@localhost:3306/test_db"/>
    <env name="DB_HOST" value="127.0.0.1"/>
    <env name="DB_PORT" value="3307"/>  <!-- Different port -->
</php>
```

### 2. **Memory and Performance Settings**
Based on local machine capabilities:
```xml
<php>
    <ini name="memory_limit" value="512M"/>  <!-- Lower for weaker machines -->
    <ini name="max_execution_time" value="60"/>  <!-- Shorter timeout -->
</php>
```

### 3. **Debugging and Verbosity**
For troubleshooting specific issues:
```xml
<phpunit
    displayDetailsOnTestsThatTriggerErrors="true"
    displayDetailsOnTestsThatTriggerWarnings="true"
    displayDetailsOnTestsThatTriggerDeprecations="true"
    stopOnFailure="true"  <!-- Stop at first failure -->
    verbose="true"
>
```

### 4. **Code Coverage Settings**
For local coverage analysis:
```xml
<coverage>
    <report>
        <html outputDirectory="var/coverage-html"/>
        <text outputFile="php://stdout" showOnlySummary="true"/>
    </report>
</coverage>
```

### 5. **Test Selection**
Running only specific test suites during development:
```xml
<testsuites>
    <testsuite name="unit">
        <directory>tests/Service</directory>  <!-- Only service tests -->
        <!-- Temporarily exclude slow tests -->
        <exclude>tests/Service/SlowIntegrationTest.php</exclude>
    </testsuite>
</testsuites>
```

### 6. **External Service Mocking**
Disable external API calls:
```xml
<php>
    <env name="MOCK_EXTERNAL_APIS" value="true"/>
    <env name="LDAP_MOCK" value="true"/>
    <env name="DISABLE_EMAIL" value="true"/>
</php>
```

### 7. **Xdebug Configuration**
For step debugging:
```xml
<php>
    <ini name="xdebug.mode" value="debug,develop"/>
    <ini name="xdebug.client_host" value="host.docker.internal"/>
    <ini name="xdebug.client_port" value="9003"/>
</php>
```

### 8. **Parallel Testing Configuration**
For machines with different CPU counts:
```xml
<php>
    <env name="PARATEST_PROCESSES" value="2"/>  <!-- Fewer processes -->
</php>
```

## Creating Your Local Configuration

1. Copy the distributed configuration:
   ```bash
   cp phpunit.xml.dist phpunit.xml
   ```

2. Modify only the settings you need to change

3. The file is already in `.gitignore`, so your changes won't be committed

## Best Practices

- **Minimal Changes**: Only override what's necessary for your environment
- **Document Unusual Settings**: If you need special settings, document why in comments
- **Don't Commit**: Never force-add `phpunit.xml` to git
- **Share Common Needs**: If multiple developers need the same change, update `phpunit.xml.dist`

## Example Local phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!-- Local development configuration for John's MacBook -->
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/12.3/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    stopOnFailure="true"  <!-- Stop on first failure for faster feedback -->
    executionOrder="depends,defects"
    beStrictAboutOutputDuringTests="false"
    beStrictAboutChangesToGlobalState="false"
    cacheDirectory=".phpunit.cache"
    displayDetailsOnTestsThatTriggerErrors="true"  <!-- Show all details -->
    displayDetailsOnTestsThatTriggerWarnings="true"
    displayDetailsOnTestsThatTriggerDeprecations="true"
>
    <!-- Same source configuration as .dist -->
    <coverage/>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <exclude>
            <directory>src/Migrations</directory>
            <directory>src/DataFixtures</directory>
            <directory>vendor</directory>
            <directory>tests</directory>
            <directory>var</directory>
        </exclude>
    </source>

    <!-- Focus on specific tests during development -->
    <testsuites>
        <testsuite name="current-work">
            <directory>tests/Service/NewFeature</directory>
        </testsuite>
    </testsuites>

    <php>
        <server name="APP_ENV" value="test" force="true"/>
        <server name="KERNEL_CLASS" value="App\Kernel"/>
        <server name="SHELL_VERBOSITY" value="3"/>  <!-- Maximum verbosity -->

        <!-- Local database on different port -->
        <env name="DATABASE_URL" value="mysql://root:root@127.0.0.1:3307/test"/>

        <!-- Lower memory limit for laptop -->
        <ini name="memory_limit" value="1G"/>

        <!-- Xdebug settings for debugging -->
        <ini name="xdebug.mode" value="debug"/>

        <!-- Show all deprecations during development -->
        <env name="SYMFONY_DEPRECATIONS_HELPER" value="strict"/>
    </php>
</phpunit>
```

## Troubleshooting

If tests work in CI but fail locally (or vice versa), check for differences between:
1. Your local `phpunit.xml` (if it exists)
2. The committed `phpunit.xml.dist`
3. CI environment variables

The most common issues are:
- Different database configurations
- Memory limit differences
- Missing environment variables
- Xdebug interference