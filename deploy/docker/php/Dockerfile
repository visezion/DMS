FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    postgresql-dev \
    mysql-client \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql intl bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
