FROM php:7-fpm

RUN set -ex \
 && apt-get update \
 && apt-get upgrade \
 && apt-get install -y libpng-tools libpng16-16 libpng-dev libxml2-dev zlib1g-dev libldap2-dev \
 && docker-php-ext-install pdo_mysql ldap zip xml gd \
 && apt-get remove -y libpng-dev libxml2-dev zlib1g-dev libldap2-dev \
# clean up
 && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false -o APT::AutoRemove::SuggestsImportant=false ${buildDeps} \
 && apt-get -y clean \
 && rm -rf /usr/src/* \
 && rm -rf /tmp/* \
 && rm -rf /var/tmp/* \
 && for logs in `find /var/log -type f`; do > ${logs}; done \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /var/cache/apt/*.bin \
 && rm -rf /usr/share/man/* /usr/share/groff/* /usr/share/info/* /usr/share/lintian/* /usr/share/linda/* /var/cache/man/* /usr/share/doc/*


COPY . /var/www/html
RUN ln -s /var/www/html/web/app.php /var/www/html/web/index.php

RUN mkdir -p /var/www/html/app/logs \
 && mkdir -p /var/www/html/app/cache \
 && chmod ugo+rwX /var/www/html/app/logs /var/www/html/app/cache

VOLUME /var/www/html/app/logs /var/www/html/app/cache

