FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        postgresql-dev \
        unzip \
    && docker-php-ext-install \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && addgroup -g "${GID}" app \
    && adduser -D -u "${UID}" -G app app

RUN apk add --no-cache su-exec

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/tradeia-entrypoint
COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

RUN chmod +x /usr/local/bin/tradeia-entrypoint

ENTRYPOINT ["tradeia-entrypoint"]
CMD ["php-fpm"]
