# Test Execution Analysis - GitHub CI vs Local `make test`

## 🔍 CRITICAL DIFFERENCES IDENTIFIED

### **1. Docker Compose File Usage**

#### **GitHub CI (.github/workflows/ci.yml:34, 70)**
```bash
docker compose -f compose.yml -f compose.dev.yml run --rm app composer install
docker compose -f compose.yml -f compose.dev.yml run --rm -e APP_ENV=test app bin/phpunit
```
❌ **PROBLEM**: References `compose.dev.yml` which **DOES NOT EXIST**

#### **Local Makefile (line 72)**
```bash
docker compose run --rm -e APP_ENV=test app-dev php -d memory_limit=512M ./bin/phpunit
```
✅ Uses default compose files with `compose.override.yml`

---

### **2. Service Target Differences**

#### **GitHub CI**
- **Target Service**: `app` (production service from compose.yml)
- **Image**: `ghcr.io/netresearch/timetracker:production`
- **Volumes**: Named volumes (app-pub, app-cache, app-logs)

#### **Local make test**  
- **Target Service**: `app-dev` (development service)
- **Image**: `ghcr.io/netresearch/timetracker:devbox`  
- **Volumes**: Bind mounts to local filesystem

---

### **3. Database Setup Procedures**

#### **GitHub CI (lines 36-48)**
```bash
# Start specific unittest DB service 
docker compose -f compose.yml -f compose.dev.yml up -d db_unittest

# Wait for DB readiness
for i in {1..60}; do
  docker compose -f compose.yml -f compose.dev.yml exec -T db_unittest sh -c 'mariadb -h 127.0.0.1 -uunittest -punittest -e "SELECT 1"' && break || sleep 2;
done

# Manual SQL import
docker compose -f compose.yml -f compose.dev.yml exec -T db_unittest sh -c 'mariadb -h 127.0.0.1 -uunittest -punittest unittest' < sql/full.sql
docker compose -f compose.yml -f compose.dev.yml exec -T db_unittest sh -c 'mariadb -h 127.0.0.1 -uunittest -punittest unittest' < sql/unittest/002_testdata.sql
```

#### **Local make test**
- **Relies on**: `db_unittest` service from `compose.override.yml`
- **Auto-initialization**: SQL files mounted as docker-entrypoint-initdb.d volumes
- **No manual import**: Database initialized automatically via Docker entrypoint

---

### **4. Database Service Configuration**

#### **GitHub CI Database (from missing compose.dev.yml)**
- **Unknown configuration** - file doesn't exist
- **Likely fails** on `db_unittest` service reference

#### **Local Database (compose.override.yml:31-42)**
```yaml
db_unittest:
  image: mariadb
  environment:
    - MYSQL_ROOT_PASSWORD=global123
    - MYSQL_USER=unittest  
    - MYSQL_PASSWORD=unittest
    - MYSQL_DATABASE=unittest
  volumes:
    - db-unittest-data:/var/lib/mysql
    - ./sql/unittest/001_testtables.sql:/docker-entrypoint-initdb.d/001_testtables.sql
    - ./sql/unittest/002_testdata.sql:/docker-entrypoint-initdb.d/002_testdata.sql
```

---

### **5. Memory Limit Differences**

#### **GitHub CI**
- **No memory limit** specified in PHPUnit execution

#### **Local make test**
```bash
php -d memory_limit=512M ./bin/phpunit
```
- **512M memory limit** explicitly set

---

### **6. Environment Variable Handling**

#### **GitHub CI**
```yaml
env:
  APP_ENV: test
run: |
  docker compose run --rm -e APP_ENV=test app bin/phpunit
```
- **Double environment setup** (both workflow env and docker -e)

#### **Local make test**
```bash
docker compose run --rm -e APP_ENV=test app-dev php -d memory_limit=512M ./bin/phpunit
```
- **Single environment setup**

---

## 🚨 **ROOT CAUSE ANALYSIS**

### **Primary Issue: Missing compose.dev.yml**
The GitHub CI workflow references `compose.dev.yml` which doesn't exist in the repository. This causes:

1. **Service resolution failures** 
2. **Database setup issues**
3. **Volume mounting problems**
4. **Potential test environment inconsistencies**

### **Secondary Issues:**
1. **Different Docker services** (app vs app-dev)
2. **Different volume strategies** (named vs bind mounts)
3. **Different memory limits** (none vs 512M)
4. **Different database initialization** (manual vs automatic)

---

## 📋 **IMMEDIATE ACTIONS REQUIRED**

### **1. Fix GitHub CI compose.dev.yml Reference**
```bash
# Option A: Create missing compose.dev.yml with dev service definitions
# Option B: Update CI to use existing compose.override.yml
# Option C: Update CI to use compose.yml services with proper profiles
```

### **2. Standardize Database Setup**
- Ensure both environments use the same database initialization procedure
- Verify SQL file mounting consistency
- Standardize database credentials and connection handling

### **3. Unify Memory Limits**
- Add memory limits to GitHub CI PHPUnit execution
- Ensure consistent PHP configuration between environments

### **4. Service Target Alignment**  
- Decide whether CI should use production (`app`) or development (`app-dev`) service
- Ensure service capabilities match test requirements

---

## 🎯 **RECOMMENDED SOLUTION**

### **Option 1: Update GitHub CI to use existing files**
```yaml
# Replace compose.dev.yml references with compose.override.yml
docker compose -f compose.yml -f compose.override.yml run --rm app-dev composer install
docker compose -f compose.yml -f compose.override.yml run --rm -e APP_ENV=test app-dev php -d memory_limit=512M bin/phpunit
```

### **Option 2: Create missing compose.dev.yml**
- Copy relevant services from compose.override.yml
- Adapt for CI environment requirements
- Maintain local/CI separation

### **Option 3: Simplify to use profiles**
```yaml 
# Use compose.yml with test profile
COMPOSE_PROFILES=test docker compose up -d db-test
COMPOSE_PROFILES=test docker compose run --rm -e APP_ENV=test app-dev php -d memory_limit=512M bin/phpunit
```

---

## ⚠️ **IMPACT OF MISALIGNMENT**

1. **Different test results** between local and CI environments
2. **CI failures** due to missing compose.dev.yml file
3. **Database state inconsistencies** 
4. **Memory-related test differences**
5. **Volume mounting discrepancies** affecting file access

This misalignment could explain why tests behave differently in different environments and why CI might be failing while local tests pass (or vice versa).

---

## 📋 **ADDITIONAL CRITICAL ISSUE: Manual SQL Copy Process**

### **sql/unittest/001_testtables.sql Management**

#### **Current Process (PROBLEMATIC)**
```bash
# Manual script: tests/prepare-test-sql.sh
sed '1s/^/USE unittest;\n/' ../sql/full.sql > ../sql/unittest/001_testtables.sql
```

#### **Issues Identified:**
1. **Manual Process**: Requires manual execution of `prepare-test-sql.sh`
2. **GitIgnored File**: `/sql/unittest/001_testtables.sql` is in .gitignore
3. **Synchronization Risk**: `001_testtables.sql` can drift from `sql/full.sql`
4. **CI Environment**: Script may not be executed, causing missing file errors
5. **Developer Onboarding**: New developers must know to run this script

#### **File Status:**
- **sql/full.sql**: 12,505 bytes (tracked)
- **sql/unittest/001_testtables.sql**: 12,505 bytes (gitignored) 
- **Files are identical** (currently in sync)
- **Script location**: `tests/prepare-test-sql.sh`

#### **Impact on CI/Test Environment:**
If `001_testtables.sql` is missing in CI (due to .gitignore), database initialization fails completely, making all tests impossible to run.

#### **Recommended Solutions:**
1. **Option A**: Auto-generate during docker build/init
2. **Option B**: Use `sql/full.sql` directly in docker-entrypoint-initdb.d with database switching
3. **Option C**: Include generation in CI preparation steps
4. **Option D**: Remove from .gitignore and track the generated file

This explains another layer of CI/local environment inconsistency beyond the compose file issues.