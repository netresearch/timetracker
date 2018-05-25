FROM php:7-alpine

ENV DEBIAN_FRONTEND=noninteractive

RUN set -x \
 && echo "http://mirror1.hs-esslingen.de/pub/Mirrors/alpine/v3.4/main" > /etc/apk/repositories \
 && apk update \
 && apk upgrade --available \
 && apk add libldap \
 && apk add --virtual .build-deps openldap-dev \
 && docker-php-ext-install pdo_mysql ldap \
 && apk del .build-deps \
 && rm -rf /var/cache/apk/*

COPY . /srv/timetracker
RUN ln -s /srv/timetracker/web/app.php /srv/timetracker/web/index.php

EXPOSE 80

VOLUME /srv/timetracker/app/logs /srv/timetracker/app/cache

CMD ["php", "-S=0.0.0.0:80", "-t=/srv/timetracker/web"]