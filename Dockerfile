# check=skip=InvalidDefaultArgInFrom
# Netresearch TimeTracker - Dockerfile
#
# Build logic only - all versions defined in docker-bake.hcl (single source
# of truth — hence the deliberately default-less ARGs and the lint skip above).
# IMPORTANT: Always build with `docker bake`, never `docker build` directly
#
# Usage:
#   docker bake              # Build production image (app)
#   docker bake app-dev      # Build development image
#   docker bake app-tools    # Build tools image (CI/pre-commit)
#   docker bake all          # Build all images
#   docker bake --print      # Show build configuration

# =============================================================================
# ARGS - Values provided by docker-bake.hcl (no defaults here!)
# =============================================================================
ARG PHP_BASE_IMAGE
ARG NODE_VERSION
ARG COMPOSER_IMAGE

# =============================================================================
# COMPOSER - Stage to copy composer binary from
# =============================================================================
FROM ${COMPOSER_IMAGE} AS composer

# =============================================================================
# BASE - Runtime with PHP extensions
# =============================================================================
FROM ${PHP_BASE_IMAGE} AS base

# Install system dependencies and PHP extensions in single layer
RUN set -ex \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libldap2-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        procps \
        unzip \
        zlib1g-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/ \
    && docker-php-ext-install \
        pdo_mysql \
        ldap \
        zip \
        gd \
        intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && rm -rf /usr/share/doc/* /usr/share/man/*

# Install APCu (pinned; backs the Symfony app cache — see config/packages/cache.yaml)
ARG APCU_VERSION
RUN pecl install apcu-${APCU_VERSION} \
    && docker-php-ext-enable apcu

COPY docker/php/apcu.ini /usr/local/etc/php/conf.d/

# Worker pool sizing for the parallel page-load burst (see the file's comment).
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# Create non-root user
RUN addgroup --gid 1000 app \
    && adduser --uid 1000 --gid 1000 --disabled-password --gecos "" app

WORKDIR /var/www/html

# =============================================================================
# DEPS - Install dependencies (optimized for caching)
# =============================================================================
FROM base AS deps

# Get composer from official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install Node.js
ARG NODE_VERSION
RUN set -ex \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Bun is the package manager of the new SolidJS frontend (frontend/)
COPY --from=oven/bun:1.3.14 /usr/local/bin/bun /usr/local/bin/bun

# Copy dependency manifests first (better cache). Root npm deps are just
# Playwright + axe for e2e.
COPY --chown=app:app package.json package-lock.json ./
RUN npm ci

# Root-owned and read-only for the runtime user (docker:S6504); the deps
# stage builds as root, so bun needs no ownership change here.
COPY frontend/package.json frontend/bun.lock ./frontend/
RUN bun install --cwd frontend --frozen-lockfile

COPY --chown=app:app composer.json composer.lock symfony.lock ./

# --ignore-platform-req=php needed until laminas-ldap adds PHP 8.5 support
# See: https://github.com/laminas/laminas-ldap/issues/62
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-req=php

# Copy application code
COPY --chown=app:app . .

# Finish composer install (autoloader, scripts)
# Set APP_ENV=prod to prevent loading dev-only bundles during cache warmup
# (MakerBundle etc. are not installed with --no-dev but bundles.php tries to load them in dev mode)
ENV CAPTAINHOOK_DISABLE=true
ENV APP_ENV=prod
RUN composer dump-autoload --optimize --classmap-authoritative \
    && composer run-script post-install-cmd --no-interaction || true

# Build the SolidJS UI (Vite).
RUN bun run --cwd frontend build

# Create var directories
RUN mkdir -p var/log var/cache \
    && chown -R app:app var/

# =============================================================================
# TOOLS - Lightweight image for CI/static analysis (no DB needed)
# =============================================================================
FROM deps AS tools

# Install dev dependencies for static analysis
RUN composer install --ignore-platform-req=php

USER app

# =============================================================================
# DEV - Development image with debugging tools
# =============================================================================
FROM deps AS dev

ARG XDEBUG_VERSION

# Install dev tools
RUN set -ex \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        bash-completion \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Xdebug (debugging and coverage driver; enable coverage via XDEBUG_MODE=coverage)
RUN pecl install xdebug-${XDEBUG_VERSION} \
    && docker-php-ext-enable xdebug

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash \
    && mv /root/.symfony*/bin/symfony /usr/local/bin/symfony \
    && mkdir -p /etc/bash_completion.d \
    && symfony completion bash > /etc/bash_completion.d/symfony \
    && echo 'source /etc/bash_completion.d/symfony' >> /etc/bash.bashrc

# Install dev dependencies
RUN composer install --ignore-platform-req=php

RUN git config --global --add safe.directory '*'

# Dev runs as root for convenience (volume permissions)
ENV APP_ENV=dev
ENV APP_DEBUG=1

# =============================================================================
# E2E - Development image with Playwright and browsers pre-installed
# =============================================================================
FROM dev AS e2e

# Install Playwright + chromium including its canonical system dependencies
RUN npx playwright install chromium --with-deps

ENV APP_ENV=test

# =============================================================================
# PRODUCTION - Minimal secure image
# =============================================================================
FROM base AS production

# Copy only what's needed from deps stage. Application code and assets are
# root-owned and read-only for the runtime user (docker:S6504); only var/
# must stay writable (cache, logs).
COPY --from=deps /var/www/html/vendor /var/www/html/vendor
COPY --from=deps /var/www/html/public /var/www/html/public
COPY --from=deps /var/www/html/config /var/www/html/config
COPY --from=deps /var/www/html/bin /var/www/html/bin
COPY --from=deps /var/www/html/src /var/www/html/src
COPY --from=deps /var/www/html/templates /var/www/html/templates
COPY --from=deps /var/www/html/translations /var/www/html/translations
COPY --from=deps /var/www/html/migrations /var/www/html/migrations
COPY --from=deps /var/www/html/sql /var/www/html/sql
COPY --from=deps --chown=app:app /var/www/html/var /var/www/html/var

# Production PHP hardening (no arg values in exception traces, no error output).
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini

# Copy healthcheck script
COPY --chmod=755 docker/php/healthcheck.sh /usr/local/bin/healthcheck

# Copy the production entrypoint — applies pending DB migrations on start so a
# new image deployed over an existing database self-migrates (AUTO_MIGRATE=0 opts out)
COPY --chmod=755 docker/php/docker-entrypoint.sh /usr/local/bin/app-entrypoint

# Update CA certificates during build (requires root, done before USER switch)
RUN update-ca-certificates 2>/dev/null || true

# Symfony's Dotenv is booted unconditionally by bin/console and public/index.php
# and throws if the file is missing; runtime configuration comes from real
# environment variables (compose.yml), so an empty file is correct here.
RUN touch /var/www/html/.env

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Build provenance, surfaced read-only on /ui/admin/status. Passed by CI (docker
# bake) from the git metadata; empty on a plain local build.
ARG APP_BUILD_REVISION=""
ARG APP_BUILD_REF=""
ARG APP_BUILD_DATE=""
ENV APP_BUILD_REVISION=${APP_BUILD_REVISION}
ENV APP_BUILD_REF=${APP_BUILD_REF}
ENV APP_BUILD_DATE=${APP_BUILD_DATE}

# Run as non-root user
USER app

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD /usr/local/bin/healthcheck

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm"]

# =============================================================================
# PROFILING - Prod-like image WITH the Symfony profiler (admin-gated).
# Never the default deployment: an operator switches the server to :profiling
# on demand to capture production profiling data, then switches back. Built on
# `deps` (no Xdebug — Xdebug would skew the very timings we measure).
# =============================================================================
FROM deps AS profiling

ENV CAPTAINHOOK_DISABLE=true
ENV APP_ENV=profiling
ENV APP_DEBUG=0

# Add dev dependencies (web-profiler-bundle, debug-bundle, stopwatch) on top of
# the prod vendor tree, keep an optimized authoritative autoloader, and warm the
# profiling cache (APCu is built into the base image).
RUN composer install --ignore-platform-req=php --no-scripts \
    && composer dump-autoload --optimize --classmap-authoritative \
    && php bin/console cache:clear --no-debug \
    && php bin/console cache:warmup --no-debug \
    && chown -R app:app var/

COPY --chmod=755 docker/php/healthcheck.sh /usr/local/bin/healthcheck
COPY --chmod=755 docker/php/docker-entrypoint.sh /usr/local/bin/app-entrypoint

RUN update-ca-certificates 2>/dev/null || true

ARG APP_BUILD_REVISION=""
ARG APP_BUILD_REF=""
ARG APP_BUILD_DATE=""
ENV APP_BUILD_REVISION=${APP_BUILD_REVISION}
ENV APP_BUILD_REF=${APP_BUILD_REF}
ENV APP_BUILD_DATE=${APP_BUILD_DATE}

USER app

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD /usr/local/bin/healthcheck

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php-fpm"]
