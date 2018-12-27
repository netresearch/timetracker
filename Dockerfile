FROM php:7-fpm

RUN set -ex \
 && apt-get update -y \
 && apt-get upgrade -y \
 && apt-get install -y libzip4 libzip-dev libpng-tools libpng16-16 libpng-dev libxml2-dev zlib1g-dev libldap2-dev \
 && curl -sS -o /tmp/icu.tar.gz -L http://download.icu-project.org/files/icu4c/62.1/icu4c-62_1-src.tgz \
 && tar -zxf /tmp/icu.tar.gz -C /tmp \
 && cd /tmp/icu/source \
 && ./configure --prefix=/usr/local \
 && make \
 && make install \
 && rm -rf /tmp/icu* \
 && docker-php-ext-configure intl --with-icu-dir=/usr/local \
 && docker-php-ext-install opcache pdo_mysql ldap zip xml gd intl \
# clean up
 && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false -o APT::AutoRemove::SuggestsImportant=false \
    libzip-dev libpng-dev libxml2-dev zlib1g-dev libldap2-dev \
 && apt-get -y clean \
 && rm -rf /usr/src/* \
 && rm -rf /tmp/* \
 && rm -rf /var/tmp/* \
 && for logs in `find /var/log -type f`; do > ${logs}; done \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /var/cache/apt/*.bin \
 && rm -rf /usr/share/man/* /usr/share/groff/* /usr/share/info/* /usr/share/lintian/* /usr/share/linda/* /var/cache/man/* /usr/share/doc/*

COPY . /var/www/html

RUN mkdir -p /var/www/html/app/logs \
 && mkdir -p /var/www/html/app/cache \
 && chmod ugo+rwX /var/www/html/app/logs /var/www/html/app/cache \
 && echo "short_open_tag = off" >> /usr/local/etc/php/conf.d/symfony.ini

# replace entrypoint and add updating ca-certifcates
RUN echo "#!/bin/sh\nset -e\n/usr/sbin/update-ca-certificates\nexec \"\$@\"" > /usr/local/bin/docker-php-entrypoint

VOLUME /var/www/html/app/logs /var/www/html/app/cache
