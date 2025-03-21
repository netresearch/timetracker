FROM php:8.2-fpm AS runtime

#ENV SYMFONY_ENV=prod

RUN set -ex \
 && apt-get update -y \
 && apt-get upgrade -y \
 && apt-get install -y \
    libzip4 \
    libzip-dev \
    libpng-tools \
    libpng16-16 \
    libpng-dev \
    libxml2-dev \
    libldap2-dev \
    unzip \
    zlib1g-dev \
 && docker-php-ext-install \
    opcache \
    pdo_mysql \
    ldap \
    zip \
    xml \
    gd \
    intl \
# clean up
 && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false -o APT::AutoRemove::SuggestsImportant=false \
    libzip-dev \
    libpng-dev \
    libxml2-dev \
    zlib1g-dev \
    libldap2-dev \
 && apt-get -y clean \
 && rm -rf /usr/src/* \
 && rm -rf /tmp/* \
 && rm -rf /var/tmp/* \
 && for logs in `find /var/log -type f`; do > ${logs}; done \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /var/cache/apt/*.bin \
 && rm -rf /usr/share/man/* \
        /usr/share/groff/* \
        /usr/share/info/* \
        /usr/share/lintian/* \
        /usr/share/linda/* \
        /var/cache/man/* \
        /usr/share/doc/*


FROM runtime AS devbox

RUN apt-get update -y
RUN apt-get install -y git unzip curl
# install composer
RUN curl -sS https://getcomposer.org/installer | php
RUN mv composer.phar /usr/local/bin/composer

# Add Node.js for webpack encore
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
RUN apt-get install -y nodejs
RUN npm install -g npm@latest


FROM devbox AS builder

COPY . /var/www/html

# install the composer packages
WORKDIR /var/www/html
RUN composer install --no-dev --no-ansi
#RUN composer install --no-ansi
#RUN app/console cache:clear --no-warmup
#RUN app/console assets:install 'web'

# Build webpack assets
RUN npm install
RUN npm run build

RUN mkdir -p app/logs
RUN mkdir -p app/cache
RUN chmod ugo+rwX app/logs app/cache


FROM runtime AS production

COPY --from=builder /var/www/html /var/www/html/

# replace entrypoint and add updating ca-certifcates
RUN echo "#!/bin/sh\nset -e\n/usr/sbin/update-ca-certificates\nexec \"\$@\"" > /usr/local/bin/docker-php-entrypoint \
 && echo "short_open_tag = off" >> /usr/local/etc/php/conf.d/symfony.ini

VOLUME /var/www/html/app/logs /var/www/html/app/cache

EXPOSE 9000
WORKDIR /var/www/html
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php-fpm"]
