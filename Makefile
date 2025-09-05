# Netresearch TimeTracker — Makefile helpers

# Default profile for development  
COMPOSE_PROFILES ?= dev
export COMPOSE_PROFILES

.PHONY: help up down restart build logs sh install composer-install composer-update npm-install npm-build npm-dev npm-watch test test-parallel coverage stan psalm cs-check cs-fix check-all fix-all db-migrate cache-clear swagger twig-lint prepare-test-sql reset-test-db

help:
	@echo "Netresearch TimeTracker — common commands"
	@echo ""
	@echo "Environment profiles:"
	@echo "  COMPOSE_PROFILES=dev      # development (default)"
	@echo "  COMPOSE_PROFILES=prod     # production"  
	@echo "  COMPOSE_PROFILES=test     # testing"
	@echo ""
	@echo "Commands:"
	@echo "  make up               # start stack"
	@echo "  make down             # stop stack"
	@echo "  make restart          # restart stack"
	@echo "  make build            # build images"
	@echo "  make logs             # follow logs"
	@echo "  make sh               # shell into app container"
	@echo "  make install          # composer install + npm install"
	@echo "  make test             # run test suite"
	@echo "  make test-parallel    # run unit tests in parallel"
	@echo "  make coverage         # run tests with coverage"
	@echo "  make reset-test-db    # reset test database (for schema changes)"
	@echo "  make stan|psalm       # static analysis"
	@echo "  make cs-check|cs-fix  # coding standards"
	@echo "  make check-all        # stan + psalm + phpcs"
	@echo "  make twig-lint        # lint twig templates"
	@echo "  make fix-all          # psalm alter + cs-fixer + rector"

up:
	docker compose up -d --build

down:
	docker compose down

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

test: prepare-test-sql
	docker compose run --rm -e APP_ENV=test app-dev php -d memory_limit=512M ./bin/phpunit

test-parallel: prepare-test-sql
	docker compose run --rm -e APP_ENV=test app-dev ./bin/paratest --processes=$$(nproc) --testsuite=unit

coverage: prepare-test-sql
	docker compose run --rm -e APP_ENV=test app-dev php -d memory_limit=512M ./bin/phpunit --coverage-html var/coverage
	@echo "Coverage HTML: var/coverage/index.html"

stan:
	docker compose run --rm app-dev composer analyze

psalm:
	docker compose run --rm app-dev composer psalm

cs-check:
	docker compose run --rm app-dev composer cs-check

cs-fix:
	docker compose run --rm app-dev composer cs-fix

check-all:
	docker compose run --rm app-dev composer check:all

twig-lint:
	docker compose run --rm app-dev composer twig:lint

fix-all:
	docker compose run --rm app-dev composer fix:all

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