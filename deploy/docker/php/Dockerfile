FROM php:8.2-fpm-alpine

ARG DOTNET_CHANNEL=8.0

RUN apk add --no-cache \
    bash \
    ca-certificates \
    curl \
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
    && python3 -m venv /opt/agent-backend-venv \
    && /opt/agent-backend-venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/agent-backend-venv/bin/pip install --no-cache-dir fastapi uvicorn \
    && curl -fsSL https://dot.net/v1/dotnet-install.sh -o /tmp/dotnet-install.sh \
    && chmod +x /tmp/dotnet-install.sh \
    && /tmp/dotnet-install.sh --channel "${DOTNET_CHANNEL}" --install-dir /usr/share/dotnet \
    && ln -sf /usr/share/dotnet/dotnet /usr/bin/dotnet \
    && rm -f /tmp/dotnet-install.sh

ENV DOTNET_ROOT=/usr/share/dotnet
ENV PATH="/usr/share/dotnet:${PATH}"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
