# Netresearch TimeTracker - Dockerfile
#
# Build logic only - all versions defined in docker-bake.hcl
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
        libxml2-dev \
        libldap2-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        unzip \
        zlib1g-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/ \
    && docker-php-ext-install \
        pdo_mysql \
        ldap \
        zip \
        xml \
        gd \
        intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && rm -rf /usr/share/doc/* /usr/share/man/*

# Install APCu from source (PHP 8.5 compatibility)
RUN set -ex \
    && apt-get update \
    && apt-get install -y --no-install-recommends git \
    && git clone --depth 1 https://github.com/krakjoe/apcu.git /tmp/apcu \
    && cd /tmp/apcu \
    && phpize \
    && ./configure --enable-apcu \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable apcu \
    && apt-get purge -y git \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/php/apcu.ini /usr/local/etc/php/conf.d/

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

# Copy dependency manifests first (better cache)
COPY --chown=app:app package.json package-lock.json ./
RUN npm ci --legacy-peer-deps

COPY --chown=app:app composer.json composer.lock symfony.lock ./

# --ignore-platform-req=php needed until laminas-ldap adds PHP 8.5 support
# See: https://github.com/laminas/laminas-ldap/issues/62
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-req=php

# Copy application code
COPY --chown=app:app . .

# Finish composer install (autoloader, scripts)
ENV CAPTAINHOOK_DISABLE=true
RUN composer dump-autoload --optimize --classmap-authoritative \
    && composer run-script post-install-cmd --no-interaction || true

# Build frontend assets
RUN npm run build

# Create var directories
RUN mkdir -p var/log var/cache \
    && chown -R app:app var/

# =============================================================================
# TOOLS - Lightweight image for CI/static analysis (no DB needed)
# =============================================================================
FROM deps AS tools

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install dev dependencies for static analysis
RUN composer install --ignore-platform-req=php

USER app

# =============================================================================
# DEV - Development image with debugging tools
# =============================================================================
FROM deps AS dev

ARG XDEBUG_VERSION
ARG PCOV_VERSION

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install dev tools
RUN set -ex \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        bash-completion \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Xdebug and PCOV
RUN pecl install xdebug-${XDEBUG_VERSION} pcov-${PCOV_VERSION} \
    && docker-php-ext-enable xdebug pcov

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
# PRODUCTION - Minimal secure image
# =============================================================================
FROM base AS production

# Copy only what's needed from deps stage
COPY --from=deps --chown=app:app /var/www/html/vendor /var/www/html/vendor
COPY --from=deps --chown=app:app /var/www/html/public /var/www/html/public
COPY --from=deps --chown=app:app /var/www/html/config /var/www/html/config
COPY --from=deps --chown=app:app /var/www/html/bin /var/www/html/bin
COPY --from=deps --chown=app:app /var/www/html/src /var/www/html/src
COPY --from=deps --chown=app:app /var/www/html/templates /var/www/html/templates
COPY --from=deps --chown=app:app /var/www/html/translations /var/www/html/translations
COPY --from=deps --chown=app:app /var/www/html/sql /var/www/html/sql
COPY --from=deps --chown=app:app /var/www/html/var /var/www/html/var

# Copy healthcheck script
COPY --chmod=755 docker/php/healthcheck.sh /usr/local/bin/healthcheck

# Update CA certificates during build (requires root, done before USER switch)
RUN update-ca-certificates 2>/dev/null || true

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Run as non-root user
USER app

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD /usr/local/bin/healthcheck

EXPOSE 9000

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php-fpm"]
