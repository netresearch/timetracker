FROM php:7-fpm

RUN set -ex \
 && apt-get update \
 && apt-get upgrade \
 && apt-get install -y libldap2-dev \
 && docker-php-ext-install pdo_mysql ldap \
 && apt-get remove -y libldap2-dev \
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


COPY . /srv/timetracker
RUN ln -s /srv/timetracker/web/app.php /srv/timetracker/web/index.php

VOLUME /srv/timetracker/app/logs /srv/timetracker/app/cache

