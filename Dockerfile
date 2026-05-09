ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer
RUN apt-get update && apt-get install -y libsqlite3-dev git unzip && docker-php-ext-install pdo_sqlite && rm -rf /var/lib/apt/lists/*
