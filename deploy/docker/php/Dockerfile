FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    python3 \
    py3-pip \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    postgresql-dev \
    mysql-client \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql intl bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && ln -sf /usr/bin/python3 /usr/bin/python \
    && pip3 install --no-cache-dir fastapi uvicorn

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
