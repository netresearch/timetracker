# TimeTracker Troubleshooting Guide

## 🔍 Quick Diagnostics

### Health Check Commands
```bash
# System health check
curl http://localhost:8080/status/check

# Database connectivity
php bin/console doctrine:query:sql "SELECT 1"

# LDAP connectivity
php bin/console app:ldap:test

# JIRA integration
php bin/console app:jira:test-connection

# Cache status
php bin/console cache:pool:list
```

## 🚨 Common Issues and Solutions

### 1. Authentication Issues

#### LDAP Authentication Fails
**Symptoms**: Users cannot log in with LDAP credentials

**Solution**:
```bash
# Test LDAP connection
ldapsearch -x -H ldap://your-ldap-server:389 \
  -D "cn=admin,dc=example,dc=com" \
  -W -b "dc=example,dc=com"

# Check LDAP configuration
grep LDAP .env.local

# Enable LDAP debug logging
echo "LDAP_DEBUG=true" >> .env.local
php bin/console cache:clear
```

**Common Fixes**:
- Verify LDAP server is reachable from application server
- Check firewall rules (port 389/636)
- Ensure bind DN has read permissions
- Verify SSL certificates for LDAPS

#### Session Timeout Issues
**Symptoms**: Users logged out unexpectedly

**Solution**:
```yaml
# config/packages/framework.yaml
framework:
    session:
        cookie_lifetime: 3600  # Increase to desired seconds
        gc_maxlifetime: 3600
```

### 2. Database Performance Issues

#### Slow Query Performance
**Symptoms**: Time entries loading slowly

**Diagnosis**:
```sql
-- Check slow queries
SHOW PROCESSLIST;

-- Analyze query performance
EXPLAIN SELECT * FROM entries WHERE user_id = 1;

-- Check missing indexes
SELECT 
    table_name,
    index_name,
    column_name
FROM information_schema.statistics
WHERE table_schema = 'timetracker';
```

**Solution**:
```bash
# Run performance optimization migration
php bin/console doctrine:migrations:migrate

# Rebuild indexes
php bin/console app:database:optimize-indexes

# Clear query cache
php bin/console doctrine:cache:clear-query
```

### 3. JIRA Integration Problems

#### OAuth Token Invalid
**Symptoms**: JIRA worklog sync fails with 401 errors

**Solution**:
```bash
# Re-authenticate with JIRA
php bin/console app:jira:reauth --user=username

# Clear OAuth token cache
php bin/console cache:pool:clear cache.app

# Test JIRA connection
curl -H "Authorization: OAuth ..." \
  https://jira.example.com/rest/api/2/myself
```

#### Worklog Sync Failures
**Symptoms**: Time entries not appearing in JIRA

**Diagnosis**:
```bash
# Check sync queue
php bin/console app:jira:check-queue

# View sync errors
tail -f var/log/jira_sync.log

# Manual sync attempt
php bin/console app:jira:sync-entry --entry-id=123 --verbose
```

### 4. Performance Issues

#### High Memory Usage
**Symptoms**: Application consuming excessive memory

**Solution**:
```bash
# Check PHP memory usage
php -i | grep memory

# Optimize Composer autoloader
composer dump-autoload --optimize --apcu

# Clear all caches
php bin/console cache:clear
rm -rf var/cache/*

# Check for memory leaks
php bin/console app:memory:analyze
```

#### Slow Page Load Times
**Diagnosis**:
```bash
# Enable Symfony profiler
APP_ENV=dev php bin/console server:run

# Check asset compilation
npm run build
php bin/console assets:install

# Analyze with blackfire/xdebug
```

### 5. Docker Issues

#### Container Won't Start
**Symptoms**: Docker containers failing to start

**Solution**:
```bash
# Check container logs
docker compose logs -f app
docker compose logs -f database

# Reset containers
docker compose down -v
docker bake app-dev && docker compose up -d

# Check disk space
df -h
docker system prune -a

# Verify port availability
lsof -i :8080
lsof -i :3306
```

#### Database Connection from Container
**Symptoms**: App container cannot connect to database

**Solution**:
```bash
# Test connection from app container
docker-compose exec app ping database

# Check environment variables
docker-compose exec app printenv | grep DATABASE

# Correct connection string
DATABASE_URL="mysql://user:pass@database:3306/timetracker"
```

### 6. Testing Issues

#### PHPUnit Tests Failing
**Symptoms**: Tests fail locally but pass in CI

**Solution**:
```bash
# Reset test database
php bin/console doctrine:database:drop --force --env=test
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test

# Clear test cache
php bin/console cache:clear --env=test

# Run with verbose output
./vendor/bin/phpunit --verbose --debug
```

#### PHPStan Errors
**Symptoms**: Static analysis failures

**Solution**:
```bash
# Clear PHPStan cache
rm -rf /tmp/phpstan

# Regenerate baseline
./vendor/bin/phpstan analyse --generate-baseline

# Run with debug
./vendor/bin/phpstan analyse --debug
```

### 7. Frontend Issues

#### Assets Not Loading
**Symptoms**: JavaScript/CSS files returning 404

**Solution**:
```bash
# Rebuild assets
npm install
npm run build

# Install Symfony assets
php bin/console assets:install --symlink

# Check webpack config
npm run dev-server

# Clear browser cache
# Chrome: Ctrl+Shift+R
# Firefox: Ctrl+F5
```

#### JavaScript Errors
**Diagnosis**:
```javascript
// Enable debug mode in webpack.config.js
module.exports = {
    mode: 'development',
    devtool: 'source-map'
};
```

### 8. Email/Notification Issues

#### Emails Not Sending
**Symptoms**: Password reset emails not received

**Solution**:
```bash
# Test email configuration
php bin/console swiftmailer:email:send \
  --from=test@example.com \
  --to=user@example.com \
  --subject="Test" \
  --body="Test email"

# Check mail spool
ls -la var/spool/

# Process mail queue
php bin/console messenger:consume async
```

## 📊 Performance Optimization

### Database Optimization
```sql
-- Analyze table statistics
ANALYZE TABLE entries;

-- Optimize tables
OPTIMIZE TABLE entries, users, projects;

-- Check table fragmentation
SELECT 
    table_name,
    data_free / 1024 / 1024 as fragmentation_mb
FROM information_schema.tables
WHERE table_schema = 'timetracker'
    AND data_free > 0;
```

### Cache Optimization
```bash
# Enable OPcache
echo "opcache.enable=1" >> /etc/php/8.4/fpm/conf.d/opcache.ini
echo "opcache.memory_consumption=256" >> /etc/php/8.4/fpm/conf.d/opcache.ini

# Configure APCu
echo "apc.enabled=1" >> /etc/php/8.4/fpm/conf.d/apcu.ini
echo "apc.shm_size=128M" >> /etc/php/8.4/fpm/conf.d/apcu.ini

# Restart PHP-FPM
systemctl restart php8.4-fpm
```

## 🔧 Debug Commands

### Enable Debug Mode
```bash
# Temporary debug mode
APP_ENV=dev APP_DEBUG=1 php bin/console server:run

# Enable SQL logging
echo "DATABASE_LOGGING=true" >> .env.local

# Enable profiler
composer require --dev symfony/profiler-pack
```

### Useful Debug Commands
```bash
# Show all routes
php bin/console debug:router

# Show service configuration
php bin/console debug:container

# Show event listeners
php bin/console debug:event-dispatcher

# Check autowiring
php bin/console debug:autowiring

# Validate schema
php bin/console doctrine:schema:validate
```

## 📝 Log File Locations

```bash
# Application logs
tail -f var/log/prod.log
tail -f var/log/dev.log

# Web server logs
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log

# PHP logs
tail -f /var/log/php8.4-fpm.log

# Docker logs
docker-compose logs -f

# System logs
journalctl -u nginx -f
journalctl -u php8.4-fpm -f
```

## 🆘 Emergency Procedures

### Application Won't Start
```bash
# 1. Check PHP syntax
find src -name "*.php" -exec php -l {} \;

# 2. Clear everything
rm -rf var/cache/* var/log/*
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

# 3. Check permissions
chown -R www-data:www-data var/
chmod -R 755 var/

# 4. Reinstall dependencies
rm -rf vendor/
composer install

# 5. Safe mode
APP_ENV=prod APP_DEBUG=0 APP_SAFE_MODE=1 php bin/console
```

### Database Corruption
```bash
# 1. Backup current state
mysqldump -u root -p timetracker > backup_$(date +%Y%m%d).sql

# 2. Check tables
mysqlcheck -u root -p --check timetracker

# 3. Repair tables
mysqlcheck -u root -p --repair timetracker

# 4. Restore from backup if needed
mysql -u root -p timetracker < backup_20240315.sql
```

### Performance Emergency
```bash
# 1. Enable maintenance mode
touch var/maintenance.lock

# 2. Clear all caches
redis-cli FLUSHALL
php bin/console cache:pool:clear cache.app
rm -rf var/cache/*

# 3. Restart services
systemctl restart php8.4-fpm
systemctl restart nginx
systemctl restart redis

# 4. Disable maintenance mode
rm var/maintenance.lock
```

## 📞 Getting Help

### Collect Diagnostic Information
```bash
# Generate diagnostic report
php bin/console app:diagnostic:report > diagnostic_$(date +%Y%m%d).txt

# Information to include:
# - PHP version: php -v
# - Symfony version: php bin/console --version
# - Composer packages: composer show
# - Environment: printenv | grep -E "APP_|DATABASE_"
# - Recent logs: tail -n 100 var/log/prod.log
```

### Support Channels
- **GitHub Issues**: Report bugs and feature requests
- **Slack Channel**: #timetracker-support
- **Email**: timetracker-support@netresearch.de
- **Documentation**: /docs/README.md

## 🔄 Recovery Procedures

### Rollback Deployment
```bash
# 1. Identify last working version
git log --oneline -10

# 2. Rollback code
git checkout <last-working-commit>

# 3. Rollback database
php bin/console doctrine:migrations:migrate prev

# 4. Clear caches
php bin/console cache:clear

# 5. Restart services
systemctl restart php8.4-fpm nginx
```

### Data Recovery
```bash
# From daily backup
mysql -u root -p timetracker < /backup/daily/timetracker_$(date +%Y%m%d).sql

# From binary logs
mysqlbinlog /var/log/mysql/mysql-bin.000001 | mysql -u root -p

# Point-in-time recovery
mysqlbinlog --start-datetime="2024-03-15 10:00:00" \
            --stop-datetime="2024-03-15 11:00:00" \
            /var/log/mysql/mysql-bin.000001 | mysql -u root -p
```

## 🛡️ Security Incident Response

### Suspected Breach
```bash
# 1. Enable emergency mode
echo "EMERGENCY_MODE=true" >> .env.local

# 2. Rotate all secrets
php bin/console app:security:rotate-secrets

# 3. Force logout all users
php bin/console app:session:invalidate-all

# 4. Review audit logs
grep -E "CRITICAL|ERROR|WARNING" var/log/*.log

# 5. Generate security report
php bin/console app:security:audit > security_audit.txt
```

---

*Last Updated: 2025-01-15 | Version: 1.0*