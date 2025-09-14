# Netresearch TimeTracker — Makefile helpers

# Default profile for development  
COMPOSE_PROFILES ?= dev
export COMPOSE_PROFILES

.PHONY: help up down restart build logs sh install composer-install composer-update npm-install npm-build npm-dev npm-watch test test-parallel test-parallel-safe test-parallel-all coverage stan phpat cs-check cs-fix check-all fix-all db-migrate cache-clear swagger twig-lint prepare-test-sql reset-test-db tools-up tools-down validate-stack analyze-coverage

help:
	@echo "Netresearch TimeTracker — common commands"
	@echo ""
	@echo "Environment profiles:"
	@echo "  COMPOSE_PROFILES=dev      # development (default)"
	@echo "  COMPOSE_PROFILES=tools    # lightweight tools only (no DB)"
	@echo "  COMPOSE_PROFILES=prod     # production"  
	@echo "  COMPOSE_PROFILES=test     # testing"
	@echo ""
	@echo "Commands:"
	@echo "  make up               # start stack"
	@echo "  make down             # stop stack"
	@echo "  make tools-up         # start lightweight tools only (no DB)"
	@echo "  make tools-down       # stop tools containers"
	@echo "  make restart          # restart stack"
	@echo "  make build            # build images"
	@echo "  make logs             # follow logs"
	@echo "  make sh               # shell into app container"
	@echo "  make install          # composer install + npm install"
	@echo "  make test             # run tests fast without Xdebug (quiet output)"
	@echo "  make test-verbose     # run tests with verbose output for debugging"
	@echo "  make test-debug       # run tests with Xdebug for step debugging"
	@echo "  make test-parallel    # run unit tests in parallel (full CPU)"
	@echo "  make test-parallel-safe # run unit tests in parallel (4 cores)"
	@echo "  make test-parallel-all  # run all tests optimally (parallel + sequential)"
	@echo "  make coverage         # run tests with coverage"
	@echo "  make reset-test-db    # reset test database (for schema changes)"
	@echo "  make stan|phpat       # static analysis & architecture (fast - no DB)"
	@echo "  make cs-check|cs-fix  # coding standards (fast - no DB)"
	@echo "  make check-all        # stan + phpat + pint + twig (fast - no DB)"
	@echo "  make twig-lint        # lint twig templates (fast - no DB)"
	@echo "  make fix-all          # pint + rector (modern stack, fast - no DB)"
	@echo "  make validate-stack   # validate entire modern toolchain"
	@echo "  make analyze-coverage # analyze test coverage report"

up:
	docker compose up -d --build

down:
	docker compose down

tools-up:
	@echo "Starting lightweight development tools (no databases)..."
	COMPOSE_PROFILES=tools docker compose up -d --build

tools-down:
	@echo "Stopping tools containers..."
	COMPOSE_PROFILES=tools docker compose down

restart: down up

build:
	docker compose build

logs:
	docker compose logs -f | cat

sh:
	docker compose exec app-dev bash

install: composer-install npm-install

composer-install:
	docker compose run --rm app-dev composer install

composer-update:
	docker compose run --rm app-dev composer update

npm-install:
	docker compose run --rm app-dev npm install --legacy-peer-deps

npm-build:
	docker compose run --rm app-dev npm run build

npm-dev:
	docker compose run --rm app-dev npm run dev

npm-watch:
	docker compose run --rm app-dev npm run watch

# Fast test execution without Xdebug (default for developers)
test: prepare-test-sql
	@echo "Running tests without Xdebug for fast execution..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off app-dev php -d memory_limit=2G -d max_execution_time=0 ./bin/phpunit

# Test with Xdebug enabled for debugging failing tests
test-debug: prepare-test-sql
	@echo "Running tests with Xdebug enabled for debugging..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=debug,develop app-dev php -d memory_limit=2G -d max_execution_time=0 ./bin/phpunit

# Test with verbose configuration (full output for debugging)
test-verbose: prepare-test-sql
	@echo "Running tests with verbose output for debugging..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off app-dev php -d memory_limit=2G -d max_execution_time=0 ./bin/phpunit --configuration=config/testing/phpunit.xml.verbose

# Parallel test execution - Full CPU utilization
test-parallel: prepare-test-sql
	@echo "Running parallel tests with $$(nproc) processes..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off -e PARATEST_PARALLEL=1 app-dev ./bin/paratest --configuration=config/testing/paratest.xml --processes=$$(nproc) --testsuite=unit-parallel --max-batch-size=50

# Safe parallel execution - Limited to 4 processes
test-parallel-safe: prepare-test-sql
	@echo "Running parallel tests with 4 processes (safe mode)..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off -e PARATEST_PARALLEL=1 app-dev ./bin/paratest --configuration=config/testing/paratest.xml --processes=4 --testsuite=unit-parallel --max-batch-size=25

# Optimal test execution - Parallel for units, sequential for controllers
test-parallel-all: prepare-test-sql
	@echo "Running optimized test suite (parallel units + sequential controllers)..."
	@echo "Phase 1: Parallel unit tests..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off -e PARATEST_PARALLEL=1 app-dev ./bin/paratest --configuration=config/testing/paratest.xml --processes=$$(nproc) --testsuite=unit-parallel --max-batch-size=50
	@echo "Phase 2: Sequential controller tests..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off -e PHP_MEMORY_LIMIT=2G app-dev php -d memory_limit=2G -d max_execution_time=0 ./bin/phpunit --testsuite=controller-sequential

# Coverage with parallel execution (using PCOV for speed)
coverage: prepare-test-sql
	@echo "Running parallel test coverage with PCOV..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off -e PARATEST_PARALLEL=1 app-dev ./bin/paratest --configuration=config/testing/paratest.xml --processes=$$(nproc) --testsuite=unit-parallel --coverage-html var/coverage-parallel
	@echo "Coverage HTML: var/coverage-parallel/index.html"

# Traditional coverage (sequential, using PCOV)
coverage-sequential: prepare-test-sql
	@echo "Running sequential test coverage with PCOV..."
	docker compose run --rm -e APP_ENV=test -e XDEBUG_MODE=off -e PHP_MEMORY_LIMIT=2G app-dev php -d memory_limit=2G -d max_execution_time=0 ./bin/phpunit --coverage-html var/coverage
	@echo "Coverage HTML: var/coverage/index.html"

stan:
	@echo "Running PHPStan static analysis (lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer analyze

phpat:
	@echo "Running PHPat architecture analysis (lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer analyze:arch

cs-check:
	@echo "Running Laravel Pint code style check (batched - lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer cs-check-batch

cs-check-full:
	@echo "Running Laravel Pint code style check (full codebase - may fail due to memory)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer cs-check

cs-fix:
	@echo "Running Laravel Pint code style fix (lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer cs-fix

check-all:
	@echo "Running complete validation suite (lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer check:all

twig-lint:
	@echo "Running Twig template linting (lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer twig:lint

fix-all:
	@echo "Running modern code fixing suite (lightweight - no DB)..."
	COMPOSE_PROFILES=tools docker compose run --rm app-tools composer fix:all

db-migrate:
	docker compose run --rm app-dev bin/console doctrine:migrations:migrate -n

cache-clear:
	docker compose run --rm app-dev bin/console cache:clear

swagger:
	@echo "Open Swagger UI at http://localhost:8765/docs/swagger/index.html"

prepare-test-sql:
	@echo "Generating unittest SQL file from sql/full.sql"
	sed '1s/^/USE unittest;\n/' sql/full.sql > sql/unittest/001_testtables.sql

reset-test-db: prepare-test-sql
	@echo "Resetting test database (for schema changes)"
	docker compose down db_unittest
	docker volume rm timetracker_db-unittest-data || true
	@echo "Starting fresh test database..."
	docker compose up -d db_unittest
	@echo "Waiting for database to be ready..."
	@for i in $$(seq 1 30); do \
		if docker compose exec -T db_unittest mariadb -h 127.0.0.1 -uunittest -punittest -e "SELECT 1" >/dev/null 2>&1; then \
			echo "Database is ready!"; \
			break; \
		fi; \
		echo "Waiting for database... ($$i/30)"; \
		sleep 2; \
	done

# Validation target (replacing validate-modern-stack.sh)
validate-stack:
	@echo "🔍 Validating modern toolchain..."
	@echo "──────────────────────────────────"
	@echo "▶ Checking composer configuration..."
	@docker compose run --rm app composer validate --no-check-publish
	@echo "✅ Composer configuration valid"
	@echo ""
	@echo "▶ Running PHPStan analysis..."
	@$(MAKE) stan
	@echo "✅ PHPStan analysis passed"
	@echo ""
	@echo "▶ Running Pint code style check..."
	@$(MAKE) cs-check
	@echo "✅ Code style check passed"
	@echo ""
	@echo "▶ Running architectural tests..."
	@$(MAKE) phpat
	@echo "✅ Architecture tests passed"
	@echo ""
	@echo "🎉 All validation checks passed!"

# Coverage analysis target (replacing analyze-coverage.php location)
analyze-coverage:
	@echo "📊 Analyzing test coverage..."
	@docker compose run --rm -e APP_ENV=test app php tests/tools/analyze-coverage.php