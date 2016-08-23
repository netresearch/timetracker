FROM php:7

ENV DEBIAN_FRONTEND=noninteractive

RUN set -ex \
 && apt-get update \
 && apt-get upgrade -y \
 && apt-get install libldap2-dev -y \
    --no-install-recommends \
 && docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/ \
 && docker-php-ext-install pdo_mysql ldap \
 && apt-get clean \
 && rm -rf /tmp/* \
 && rm -rf /var/tmp/* \
 && for logs in `find /var/log -type f`; do > $logs; done \
 && rm -rf /usr/share/locale/* \
 && rm -rf /usr/share/man/* \
 && rm -rf /usr/share/doc/* \
 && rm -rf /var/lib/apt/lists/*

COPY . /srv/timetracker
RUN ln -s /srv/timetracker/web/app.php /srv/timetracker/web/index.php

EXPOSE 80

VOLUME /srv/timetracker/app/logs /srv/timetracker/app/cache

CMD ["php", "-S=0.0.0.0:80", "-t=/srv/timetracker/web"]
