FROM php:8.2-fpm AS runtime

ENV APP_ENV=prod
ENV APP_DEBUG=0

RUN set -ex \
   && apt-get update -y \
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
   libjpeg62-turbo-dev \
   libfreetype6-dev \
   libicu-dev \
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
RUN apt-get install -y git unzip curl bash-completion

# install composer
RUN curl -sS https://getcomposer.org/installer | php
RUN mv composer.phar /usr/local/bin/composer

# install symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash
RUN mv /root/.symfony5/bin/symfony /usr/local/bin/symfony
# Setup Symfony bash completion
RUN mkdir -p /etc/bash_completion.d
RUN symfony completion bash > /etc/bash_completion.d/symfony
RUN echo 'source /etc/bash_completion.d/symfony' >> /etc/bash.bashrc

# Add Node.js for webpack encore
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
RUN apt-get install -y nodejs
RUN npm install -g npm@latest

RUN pecl install pcov \
   && docker-php-ext-enable pcov

RUN git config --global --add safe.directory '*'

FROM devbox AS app_builder

COPY . /var/www/html

RUN npm install --legacy-peer-deps

# install the composer packages
WORKDIR /var/www/html
RUN composer install --no-dev --no-ansi
# should happen in entrypoint
#RUN composer dump-env prod

RUN mkdir -p var/log
RUN mkdir -p var/cache
RUN chmod ugo+rwX var/log var/cache


FROM app_builder AS assets_builder

RUN composer install --no-ansi

# Build webpack assets
RUN npm run build

FROM runtime AS production

COPY --from=app_builder /var/www/html/vendor /var/www/html/vendor
COPY --from=app_builder /var/www/html/public /var/www/html/public
COPY --from=app_builder /var/www/html/config /var/www/html/config
COPY --from=app_builder /var/www/html/bin /var/www/html/bin
COPY --from=app_builder /var/www/html/src /var/www/html/src
COPY --from=app_builder /var/www/html/templates /var/www/html/templates
COPY --from=app_builder /var/www/html/translations /var/www/html/translations
COPY --from=app_builder /var/www/html/var /var/www/html/var
COPY --from=app_builder /var/www/html/sql /var/www/html/sql
#COPY --from=app_builder /var/www/html/.env.local.php /var/www/html/.env.local.php

COPY --from=assets_builder /var/www/html/public/build /var/www/html/public/build

# replace entrypoint and add updating ca-certifcates
RUN echo "#!/bin/sh\nset -e\n/usr/sbin/update-ca-certificates\nexec \"\$@\"" > /usr/local/bin/docker-php-entrypoint

VOLUME /var/www/html/var/log /var/www/html/var/cache

EXPOSE 9000
WORKDIR /var/www/html
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php-fpm"]
