# Netresearch TimeTracker — Makefile helpers

.PHONY: help up down restart build logs sh install composer-install composer-update npm-install npm-build npm-dev npm-watch test test-parallel coverage stan psalm cs-check cs-fix check-all fix-all db-migrate cache-clear swagger twig-lint

help:
	@echo "Netresearch TimeTracker — common commands"
	@echo "make up             # start stack"
	@echo "make down           # stop stack"
	@echo "make restart        # restart stack"
	@echo "make build          # build images"
	@echo "make logs           # follow logs"
	@echo "make sh             # shell into app container"
	@echo "make install        # composer install + npm install"
	@echo "make test           # run test suite"
	@echo "make test-parallel  # run unit tests in parallel"
	@echo "make coverage       # run tests with coverage"
	@echo "make stan|psalm     # static analysis"
	@echo "make cs-check|cs-fix# coding standards"
	@echo "make check-all      # stan + psalm + phpcs"
	@echo "make twig-lint      # lint twig templates"
	@echo "make fix-all        # psalm alter + cs-fixer + rector"

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
	docker compose exec app bash

install: composer-install npm-install

composer-install:
	docker compose -f compose.yml -f compose.dev.yml run --rm app composer install

composer-update:
	docker compose -f compose.yml -f compose.dev.yml run --rm app composer update

npm-install:
	docker compose -f compose.yml -f compose.dev.yml run --rm app npm install --legacy-peer-deps

npm-build:
	docker compose -f compose.yml -f compose.dev.yml run --rm app npm run build

npm-dev:
	docker compose -f compose.yml -f compose.dev.yml run --rm app npm run dev

npm-watch:
	docker compose -f compose.yml -f compose.dev.yml run --rm app npm run watch

test:
	docker compose -f compose.yml -f compose.dev.yml run --rm -e APP_ENV=test app php -d memory_limit=512M ./bin/phpunit

test-parallel:
	docker compose -f compose.yml -f compose.dev.yml run --rm -e APP_ENV=test app ./bin/paratest --processes=$$(nproc) --testsuite=unit

coverage:
	docker compose -f compose.yml -f compose.dev.yml run --rm -e APP_ENV=test app php -d memory_limit=512M ./bin/phpunit --coverage-html var/coverage
	@echo "Coverage HTML: var/coverage/index.html"

stan:
	docker compose run --rm app composer analyze

psalm:
	docker compose run --rm app composer psalm

cs-check:
	docker compose run --rm app composer cs-check

cs-fix:
	docker compose run --rm app composer cs-fix

check-all:
	docker compose run --rm app composer check:all
twig-lint:
	docker compose run --rm app composer twig:lint

fix-all:
	docker compose run --rm app composer fix:all

db-migrate:
	docker compose run --rm app bin/console doctrine:migrations:migrate -n

cache-clear:
	docker compose run --rm app bin/console cache:clear

swagger:
	@echo "Open Swagger UI at http://localhost:8765/docs/swagger/index.html"