FROM php:8.3-fpm-alpine

RUN apk add --no-cache git unzip libpq-dev icu-dev oniguruma-dev bash \
    && docker-php-ext-install pdo pdo_pgsql intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
