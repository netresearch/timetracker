# Database Test Strategy - Complete Guide

## 🎯 **STRATEGY OVERVIEW**

**Optimal Balance**: Fast daily development + Clean isolation + Schema flexibility

---

## 🚀 **DAILY DEVELOPMENT** (Normal Test Runs)

### **Commands**
```bash
make test           # Standard test suite
make test-parallel  # Parallel execution  
make coverage       # With coverage analysis
```

### **How It Works**
1. **Persistent Database**: Uses existing `db_unittest` volume
2. **Transaction Isolation**: Each test runs in `beginTransaction()` + `rollBack()`
3. **Clean State**: Database remains uncontaminated between tests
4. **Fast Execution**: No database restart overhead

### **Test Isolation Mechanism**
```php
// AbstractWebTestCase.php
protected function setUp(): void {
    $dbal->beginTransaction();  // Start transaction
}

protected function tearDown(): void {
    $dbal->rollBack();  // Automatic cleanup
}
```

---

## 🔄 **SCHEMA CHANGES** (Rare Events)

### **When Needed**
- Database schema modifications (ALTER TABLE, new tables, etc.)
- Changes to `sql/full.sql` 
- Test data structure changes in `sql/unittest/002_testdata.sql`

### **Commands**
```bash
make reset-test-db  # Recreate database with fresh schema
make test          # Run tests against fresh database  
```

### **What Happens**
1. **Generate SQL**: Updates `sql/unittest/001_testtables.sql` from `sql/full.sql`
2. **Remove Volume**: `docker volume rm timetracker_db-unittest-data`
3. **Recreate Database**: Fresh MariaDB container with new schema
4. **Wait for Ready**: Automated readiness check (30 retries)
5. **Auto-Initialize**: Docker loads SQL files via `docker-entrypoint-initdb.d`

---

## 🏗️ **TECHNICAL ARCHITECTURE**

### **Database Services**
```yaml
# compose.override.yml
db_unittest:
  image: mariadb
  environment:
    MYSQL_DATABASE: unittest
    MYSQL_USER: unittest
    MYSQL_PASSWORD: unittest
  volumes:
    - db-unittest-data:/var/lib/mysql
    - ./sql/unittest/001_testtables.sql:/docker-entrypoint-initdb.d/001_testtables.sql
    - ./sql/unittest/002_testdata.sql:/docker-entrypoint-initdb.d/002_testdata.sql
```

### **SQL File Generation**
```bash
# Makefile: prepare-test-sql target
sed '1s/^/USE unittest;\n/' sql/full.sql > sql/unittest/001_testtables.sql
```
- **Source**: `sql/full.sql` (production schema)
- **Target**: `sql/unittest/001_testtables.sql` (test schema with USE statement)
- **Automatic**: Generated on-demand, no manual script execution

### **Transaction Isolation**
```php
class AbstractWebTestCase extends WebTestCase {
    protected $useTransactions = true;  // Default: enabled
    
    // Each test gets clean database state via rollback
    // Only tests needing fresh schema call forceReset()
}
```

---

## ✅ **CI/LOCAL ALIGNMENT**

### **GitHub CI**
```yaml
- name: Prepare config files and SQL  
  run: |
    sed '1s/^/USE unittest;\n/' sql/full.sql > sql/unittest/001_testtables.sql

- name: Run tests (PHPUnit)
  env:
    COMPOSE_PROFILES: dev
  run: |
    make test
```

### **Local Development** 
```bash
# Same commands, same behavior
COMPOSE_PROFILES=dev make test
```

### **Perfect Alignment**
| Component | Local | CI | Status |
|-----------|-------|-------|---------|
| Profile | `dev` | `dev` | ✅ Identical |
| Service | `app-dev` | `app-dev` | ✅ Identical |
| SQL Generation | `prepare-test-sql` | `prepare-test-sql` | ✅ Identical |
| Database Strategy | Transaction isolation | Fresh DB per run | ✅ Both valid |
| Test Command | `make test` | `make test` | ✅ Identical |

---

## 🎉 **BENEFITS**

### **Performance**
- ⚡ **Fast Tests**: No database restart for normal runs
- 🔄 **Efficient Isolation**: Transaction rollback vs full reset
- 📊 **Parallel Ready**: Transaction isolation supports parallel execution

### **Reliability** 
- 🧪 **Clean Tests**: Each test starts with clean database state
- 🔒 **No Contamination**: Transaction rollback prevents test interference  
- 🎯 **Deterministic**: Consistent results across runs

### **Flexibility**
- 🔧 **Schema Updates**: `make reset-test-db` for structural changes
- 🛠️ **Developer Choice**: Normal vs fresh database as needed
- 📋 **CI Compatibility**: Works in both persistent and ephemeral environments

---

## 📚 **DEVELOPER GUIDE**

### **Day-to-Day Testing**
```bash
# Normal development - fast and reliable
make test
```

### **After Schema Changes**
```bash
# Changed sql/full.sql? Reset the database
make reset-test-db
make test
```

### **Debugging Database Issues**
```bash
# Check current database state
docker compose exec db_unittest mariadb -h 127.0.0.1 -uunittest -punittest unittest

# Force fresh database
make reset-test-db

# Check what SQL will be loaded
cat sql/unittest/001_testtables.sql
```

### **Parallel Testing**
```bash
# Works with transaction isolation
make test-parallel
```

This strategy provides the **optimal balance** of speed, reliability, and flexibility for both local development and CI environments.